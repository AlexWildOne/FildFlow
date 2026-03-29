<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use RoutesPro\Services\LocationDeduplicator;
use RoutesPro\Support\Permissions;

if (!defined('ABSPATH')) exit;

class CommercialController {
    const NS = 'routespro/v1';
    const CACHE_VERSION_OPTION = 'routespro_commercial_cache_version';

    public function register_routes(): void {
        register_rest_route(self::NS, '/commercial-search', [[
            'methods' => 'GET',
            'callback' => [$this, 'search'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front(); },
        ]]);
        register_rest_route(self::NS, '/commercial-filters', [[
            'methods' => 'GET',
            'callback' => [$this, 'filters'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front(); },
        ]]);
        register_rest_route(self::NS, '/locations/lookup', [[
            'methods' => 'GET',
            'callback' => [$this, 'lookup'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front(); },
        ]]);
        register_rest_route(self::NS, '/locations/import-template', [[
            'methods' => 'GET',
            'callback' => [$this, 'template'],
            'permission_callback' => function(){ return current_user_can('routespro_manage'); },
        ]]);
    }

    public static function bump_cache_version(): void {
        $current = (int) get_option(self::CACHE_VERSION_OPTION, 1);
        update_option(self::CACHE_VERSION_OPTION, $current + 1, false);
    }

    private function cache_version(): int {
        return (int) get_option(self::CACHE_VERSION_OPTION, 1);
    }

    private function cache_key(string $prefix, array $payload): string {
        return 'routespro_' . $prefix . '_' . md5(wp_json_encode([$this->cache_version(), $payload]));
    }

    private function get_cached(string $prefix, array $payload) {
        return get_transient($this->cache_key($prefix, $payload));
    }

    private function set_cached(string $prefix, array $payload, $value, int $ttl = 120): void {
        set_transient($this->cache_key($prefix, $payload), $value, $ttl);
    }

    private function normalize_string_list(array $values): array {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') continue;
            $out[$value] = $value;
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($out);
    }

    private function normalize_category_tree(array $rows): array {
        $items = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if (!$id) continue;
            $items[$id] = [
                'id' => $id,
                'name' => (string)($row['name'] ?? ''),
                'slug' => (string)($row['slug'] ?? ''),
                'parent_id' => (int)($row['parent_id'] ?? 0),
            ];
        }
        uasort($items, function($a, $b){
            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        return array_values($items);
    }

    private function subcategory_aliases(string $name): array {
        $map = [
            'continente' => ['continente', 'continente modelo'],
            'continente bom dia' => ['continente bom dia'],
            'pingo doce' => ['pingo doce'],
            'auchan' => ['auchan', 'jumbo'],
            'mercadona' => ['mercadona'],
            'e.leclerc' => ['e.leclerc', 'eleclerc', 'leclerc'],
            'minipreço' => ['minipreço', 'mini preco', 'mini preço'],
            'lidl' => ['lidl'],
            'aldi' => ['aldi'],
            'intermarché' => ['intermarché', 'intermarche'],
            'super / hiper poupança' => ['poupança', 'poupanca', 'super poupança', 'super poupanca', 'hiper poupança', 'hiper poupanca'],
            'worten' => ['worten'],
            'fnac' => ['fnac'],
            'radio popular' => ['radio popular', 'rádio popular'],
            'staples' => ['staples'],
            'darty' => ['darty'],
            'makro' => ['makro', 'macro'],
            'recheio' => ['recheio'],
            'mcunha' => ['mcunha', 'm cunha'],
            'marabuto' => ['marabuto', 'matarabuto'],
            'malaquias' => ['malaquias'],
            'grossão' => ['grossão', 'grossao'],
            'nortenho' => ['nortenho'],
            'pereira e santos' => ['pereira e santos'],
            'a. ezequiel' => ['a. ezequiel', 'a ezequiel'],
            'garcias' => ['garcias'],
            'arcol' => ['arcol'],
        ];
        $key = strtolower(trim($name));
        return $map[$key] ?? ($key !== '' ? [$name] : []);
    }

    private function normalize_category_payload(array $row): array {
        $category_id = (int)($row['category_id'] ?? 0);
        $subcategory_id = (int)($row['subcategory_id'] ?? 0);
        $category_name = (string)($row['category_name'] ?? '');
        $subcategory_name = (string)($row['subcategory_name'] ?? '');
        $parent_category_id = (int)($row['parent_category_id'] ?? 0);
        $parent_category_name = (string)($row['parent_category_name'] ?? '');

        if ($subcategory_id && $parent_category_id) {
            $row['category_id'] = $parent_category_id;
            $row['category_name'] = $parent_category_name !== '' ? $parent_category_name : $category_name;
            $row['subcategory_id'] = $subcategory_id;
            $row['subcategory_name'] = $subcategory_name;
            $row['effective_category_id'] = $parent_category_id;
            $row['effective_subcategory_id'] = $subcategory_id;
            return $row;
        }

        if ($category_id && !$subcategory_id && $parent_category_id) {
            $row['category_id'] = $parent_category_id;
            $row['category_name'] = $parent_category_name !== '' ? $parent_category_name : $category_name;
            $row['subcategory_id'] = $category_id;
            $row['subcategory_name'] = $category_name;
            $row['effective_category_id'] = $parent_category_id;
            $row['effective_subcategory_id'] = $category_id;
            return $row;
        }

        $row['effective_category_id'] = (int)($row['category_id'] ?? 0);
        $row['effective_subcategory_id'] = (int)($row['subcategory_id'] ?? 0);
        return $row;
    }

    private function where(WP_REST_Request $req, array &$args): string {
        global $wpdb;
        $cats_tbl = $wpdb->prefix . 'routespro_categories';
        $where = ["(l.location_type IN ('pdv', '') OR l.location_type IS NULL)"];
        $district = sanitize_text_field($req->get_param('district') ?: '');
        $county = sanitize_text_field($req->get_param('county') ?: '');
        $city = sanitize_text_field($req->get_param('city') ?: '');
        $category_id = absint($req->get_param('category_id') ?: 0);
        $subcategory_id = absint($req->get_param('subcategory_id') ?: 0);
        $q = sanitize_text_field($req->get_param('q') ?: '');
        if ($subcategory_id) {
            $wpdb->get_row($wpdb->prepare("SELECT id,name,parent_id FROM {$cats_tbl} WHERE id=%d LIMIT 1", $subcategory_id), ARRAY_A);
        }
        $validated = $req->get_param('validated');
        $only_active = $req->get_param('only_active');
        $client_id = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        $owner_user_id = absint($req->get_param('owner_user_id') ?: 0);
        $role = sanitize_key($req->get_param('role') ?: '');
        if ($district !== '') { $where[] = 'l.district=%s'; $args[] = $district; }
        if ($county !== '') { $where[] = 'l.county=%s'; $args[] = $county; }
        if ($city !== '') { $where[] = 'l.city=%s'; $args[] = $city; }
        if ($subcategory_id) {
            $where[] = '(l.subcategory_id=%d OR (l.subcategory_id IS NULL AND l.category_id=%d))';
            $args[] = $subcategory_id;
            $args[] = $subcategory_id;
        }
        elseif ($category_id) {
            $where[] = '(l.category_id=%d OR parent_cat.id=%d OR legacy_parent_cat.parent_id=%d OR l.subcategory_id IN (SELECT id FROM {$cats_tbl} WHERE parent_id=%d))';
            $args[] = $category_id;
            $args[] = $category_id;
            $args[] = $category_id;
            $args[] = $category_id;
        }
        if ($project_id) { $where[] = '(cl.project_id=%d OR l.project_id=%d)'; $args[] = $project_id; $args[] = $project_id; }
        if ($owner_user_id) { $where[] = 'cl.assigned_to=%d'; $args[] = $owner_user_id; }
        if ($role !== '') {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}routespro_assignments a INNER JOIN {$wpdb->prefix}routespro_routes rr ON rr.id = a.route_id WHERE a.role=%s AND ((cl.assigned_to IS NOT NULL AND (rr.owner_user_id = cl.assigned_to OR a.user_id = cl.assigned_to)) OR cl.assigned_to IS NULL) AND rr.client_id = COALESCE(clp.client_id,l.client_id) AND (COALESCE(cl.project_id,l.project_id) = 0 OR rr.project_id = COALESCE(cl.project_id,l.project_id)))";
            $args[] = $role;
        }
        if ($client_id && !$owner_user_id) { $where[] = '(clp.client_id=%d OR l.client_id=%d)'; $args[] = $client_id; $args[] = $client_id; }
        list($scopeSql, $scopeArgs) = Permissions::scope_sql('', 'COALESCE(clp.client_id,l.client_id)', 'COALESCE(cl.project_id,l.project_id)');
        if ($scopeSql !== '1=1') { $where[] = $scopeSql; $args = array_merge($args, $scopeArgs); }
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.phone LIKE %s OR l.email LIKE %s OR l.external_ref LIKE %s OR l.place_id LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like, $like);
        }
        if ($validated !== null && $validated !== '') { $where[] = 'l.is_validated=%d'; $args[] = absint($validated) ? 1 : 0; }
        if ($only_active !== null && $only_active !== '') { $where[] = 'l.is_active=%d'; $args[] = absint($only_active) ? 1 : 0; }
        return implode(' AND ', $where);
    }

    public function filters(WP_REST_Request $req): WP_REST_Response {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $payload = [
            'client_id' => absint($req->get_param('client_id') ?: 0),
            'project_id' => absint($req->get_param('project_id') ?: 0),
            'owner_user_id' => absint($req->get_param('owner_user_id') ?: 0),
            'role' => sanitize_key($req->get_param('role') ?: ''),
            'district' => sanitize_text_field($req->get_param('district') ?: ''),
            'county' => sanitize_text_field($req->get_param('county') ?: ''),
            'city' => sanitize_text_field($req->get_param('city') ?: ''),
            'category_id' => absint($req->get_param('category_id') ?: 0),
            'subcategory_id' => absint($req->get_param('subcategory_id') ?: 0),
            'validated' => $req->get_param('validated'),
            'only_active' => $req->get_param('only_active'),
        ];
        $cached = $this->get_cached('commercial_filters', $payload);
        if (is_array($cached)) {
            return new WP_REST_Response($cached, 200);
        }

        $filterReq = new WP_REST_Request('GET', '/');
        foreach (['client_id','project_id','owner_user_id','role','district','county','city','category_id','subcategory_id','validated','only_active'] as $param) {
            $value = $req->get_param($param);
            if ($value !== null && $value !== '') {
                $filterReq->set_param($param, $value);
            }
        }

        $args = [];
        $where = $this->where($filterReq, $args);

        $sql = "SELECT l.district,l.county,l.city,l.category_id,l.subcategory_id,
                       c.id AS cat_id,c.name AS cat_name,c.slug AS cat_slug,c.parent_id AS cat_parent_id,
                       sc.id AS sub_id,sc.name AS sub_name,sc.slug AS sub_slug,sc.parent_id AS sub_parent_id,
                       COALESCE(parent_cat.id, legacy_parent.id) AS parent_category_id,
                       COALESCE(parent_cat.name, legacy_parent.name) AS parent_category_name
                FROM {$px}locations l
                LEFT JOIN {$px}categories c ON c.id=l.category_id
                LEFT JOIN {$px}categories legacy_parent_cat ON legacy_parent_cat.id=l.category_id
                LEFT JOIN {$px}categories legacy_parent ON legacy_parent.id=legacy_parent_cat.parent_id
                LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id
                LEFT JOIN {$px}categories parent_cat ON parent_cat.id=sc.parent_id
                LEFT JOIN {$px}campaign_locations cl ON cl.location_id=l.id
                LEFT JOIN {$px}projects clp ON clp.id=cl.project_id
                WHERE {$where}
                LIMIT 5000";
        $rows = $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);

        $districts = [];
        $countiesByDistrict = [];
        $citiesByDistrict = [];
        $categories = [];
        foreach ($rows as $row) {
            $district = trim((string)($row['district'] ?? ''));
            $county = trim((string)($row['county'] ?? ''));
            $city = trim((string)($row['city'] ?? ''));
            if ($district !== '') {
                $districts[$district] = $district;
                if ($county !== '') {
                    if (!isset($countiesByDistrict[$district])) $countiesByDistrict[$district] = [];
                    $countiesByDistrict[$district][$county] = $county;
                }
                if ($city !== '') {
                    if (!isset($citiesByDistrict[$district])) $citiesByDistrict[$district] = [];
                    $citiesByDistrict[$district][$city] = $city;
                }
            }

            $catId = (int)($row['cat_id'] ?? 0);
            $catParent = (int)($row['cat_parent_id'] ?? 0);
            if ($catId) {
                $categories[$catId] = [
                    'id' => $catId,
                    'name' => (string)($row['cat_name'] ?? ''),
                    'slug' => (string)($row['cat_slug'] ?? ''),
                    'parent_id' => $catParent,
                ];
            }

            $subId = (int)($row['sub_id'] ?? 0);
            if ($subId) {
                $categories[$subId] = [
                    'id' => $subId,
                    'name' => (string)($row['sub_name'] ?? ''),
                    'slug' => (string)($row['sub_slug'] ?? ''),
                    'parent_id' => (int)($row['sub_parent_id'] ?? 0),
                ];
            }

            $effectiveParentId = (int)($row['parent_category_id'] ?? 0);
            $effectiveParentName = (string)($row['parent_category_name'] ?? '');
            if ($effectiveParentId && !isset($categories[$effectiveParentId])) {
                $categories[$effectiveParentId] = [
                    'id' => $effectiveParentId,
                    'name' => $effectiveParentName,
                    'slug' => sanitize_title($effectiveParentName),
                    'parent_id' => 0,
                ];
            }
        }

        foreach ($countiesByDistrict as $district => $values) {
            $countiesByDistrict[$district] = $this->normalize_string_list(array_values($values));
        }
        foreach ($citiesByDistrict as $district => $values) {
            $citiesByDistrict[$district] = $this->normalize_string_list(array_values($values));
        }

        if (!$categories) {
            $categoryRows = $wpdb->get_results("SELECT id,name,slug,parent_id FROM {$px}categories WHERE is_active=1 ORDER BY name ASC", ARRAY_A) ?: [];
            $categories = [];
            foreach ($categoryRows as $row) {
                $categories[(int)$row['id']] = [
                    'id' => (int)$row['id'],
                    'name' => (string)($row['name'] ?? ''),
                    'slug' => (string)($row['slug'] ?? ''),
                    'parent_id' => (int)($row['parent_id'] ?? 0),
                ];
            }
        }

        if (!$districts) {
            $districts = array_combine(\RoutesPro\Support\GeoPT::districts(), \RoutesPro\Support\GeoPT::districts()) ?: [];
        }
        if (!$countiesByDistrict) {
            $countiesByDistrict = \RoutesPro\Support\GeoPT::counties_by_district();
        }
        if (!$citiesByDistrict) {
            $citiesByDistrict = \RoutesPro\Support\GeoPT::cities_by_district();
        }

        $response = [
            'districts' => $this->normalize_string_list(array_values($districts)),
            'countiesByDistrict' => $countiesByDistrict,
            'citiesByDistrict' => $citiesByDistrict,
            'categories' => $this->normalize_category_tree(array_values($categories)),
        ];
        $this->set_cached('commercial_filters', $payload, $response, 180);
        return new WP_REST_Response($response, 200);
    }

    public function search(WP_REST_Request $req): WP_REST_Response {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $page = max(1, absint($req->get_param('page') ?: 1));
        $per_page = max(1, min(500, absint($req->get_param('per_page') ?: 100)));
        $offset = ($page - 1) * $per_page;
        $cachePayload = [
            'page' => $page,
            'per_page' => $per_page,
            'district' => sanitize_text_field($req->get_param('district') ?: ''),
            'county' => sanitize_text_field($req->get_param('county') ?: ''),
            'city' => sanitize_text_field($req->get_param('city') ?: ''),
            'category_id' => absint($req->get_param('category_id') ?: 0),
            'subcategory_id' => absint($req->get_param('subcategory_id') ?: 0),
            'client_id' => absint($req->get_param('client_id') ?: 0),
            'project_id' => absint($req->get_param('project_id') ?: 0),
            'owner_user_id' => absint($req->get_param('owner_user_id') ?: 0),
            'role' => sanitize_key($req->get_param('role') ?: ''),
            'q' => sanitize_text_field($req->get_param('q') ?: ''),
            'validated' => $req->get_param('validated'),
            'only_active' => $req->get_param('only_active'),
        ];
        $cached = $this->get_cached('commercial_search', $cachePayload);
        if (is_array($cached)) {
            return new WP_REST_Response($cached, 200);
        }

        $args = [];
        $where = $this->where($req, $args);
        $limit = max(500, min(5000, $offset + ($per_page * 3)));
        $sql = "SELECT l.*, c.name AS category_name, sc.name AS subcategory_name, COALESCE(parent_cat.id, legacy_parent.id) AS parent_category_id, COALESCE(parent_cat.name, legacy_parent.name) AS parent_category_name
                FROM {$px}locations l
                LEFT JOIN {$px}categories c ON c.id=l.category_id
                LEFT JOIN {$px}categories legacy_parent_cat ON legacy_parent_cat.id=l.category_id
                LEFT JOIN {$px}categories legacy_parent ON legacy_parent.id=legacy_parent_cat.parent_id
                LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id
                LEFT JOIN {$px}categories parent_cat ON parent_cat.id=sc.parent_id
                LEFT JOIN {$px}campaign_locations cl ON cl.location_id=l.id
                LEFT JOIN {$px}projects clp ON clp.id=cl.project_id
                WHERE {$where}
                ORDER BY l.updated_at DESC, l.id DESC LIMIT %d";
        $queryArgs = array_merge($args, [$limit]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$queryArgs), ARRAY_A) ?: [];
        $deduped = array_map([$this, 'normalize_category_payload'], LocationDeduplicator::dedupe_rows($rows));
        $total = count($deduped);
        $items = array_slice($deduped, $offset, $per_page);
        $stats = [
            'total_visible' => $total,
            'with_coords' => count(array_filter($deduped, fn($x) => $x['lat'] !== null && $x['lng'] !== null)),
            'google_count' => count(array_filter($deduped, fn($x) => ($x['source'] ?? '') === 'google')),
            'validated_count' => count(array_filter($deduped, fn($x) => intval($x['is_validated'] ?? 0) === 1)),
        ];
        $response = [
            'items' => $items,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'stats' => $stats,
        ];
        $this->set_cached('commercial_search', $cachePayload, $response, 90);
        return new WP_REST_Response($response, 200);
    }

    public function lookup(WP_REST_Request $req): WP_REST_Response {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $limit = max(1, min(50, absint($req->get_param('limit') ?: 20)));
        $args = [];
        $where = $this->where($req, $args);
        $sql = "SELECT l.id,l.name,l.address,l.phone,l.email,l.lat,l.lng,l.district,l.county,l.city,l.category_id,l.subcategory_id,l.contact_person,l.place_id,l.external_ref,c.name AS category_name,sc.name AS subcategory_name,COALESCE(parent_cat.id, legacy_parent.id) AS parent_category_id,COALESCE(parent_cat.name, legacy_parent.name) AS parent_category_name
                FROM {$px}locations l
                LEFT JOIN {$px}categories c ON c.id=l.category_id
                LEFT JOIN {$px}categories legacy_parent_cat ON legacy_parent_cat.id=l.category_id
                LEFT JOIN {$px}categories legacy_parent ON legacy_parent.id=legacy_parent_cat.parent_id
                LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id
                LEFT JOIN {$px}categories parent_cat ON parent_cat.id=sc.parent_id
                LEFT JOIN {$px}campaign_locations cl ON cl.location_id=l.id
                LEFT JOIN {$px}projects clp ON clp.id=cl.project_id
                WHERE {$where}
                ORDER BY l.name ASC, l.id DESC LIMIT 500";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        $rows = array_slice(array_map([$this, 'normalize_category_payload'], LocationDeduplicator::dedupe_rows($rows)), 0, $limit);
        $items = array_map(function($r){
            $text = trim(($r['name'] ?? '') . ' | ' . ($r['city'] ?? '') . ' | ' . ($r['address'] ?? ''));
            $r['text'] = $text;
            return $r;
        }, $rows);
        return new WP_REST_Response(['items' => $items], 200);
    }

    public function template(WP_REST_Request $req) {
        $headers = ['name','address','district','county','city','parish','postal_code','country','category','subcategory','contact_person','phone','email','website','lat','lng','external_ref','place_id','source'];
        $sample = ['Exemplo PDV','Rua Exemplo 123','Lisboa','Lisboa','Lisboa','','1000-100','Portugal','Horeca','Restaurante','Joao Silva','910000000','geral@example.com','https://example.com','38.7223','-9.1393','REF-001','','csv'];
        $csv = implode(',', $headers) . "
" . implode(',', array_map(function($v){ return '"' . str_replace('"', '""', $v) . '"'; }, $sample)) . "
";
        return new WP_REST_Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="routespro-commercial-template.csv"',
        ]);
    }
}
