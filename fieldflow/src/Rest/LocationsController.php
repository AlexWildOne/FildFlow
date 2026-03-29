<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use RoutesPro\Services\LocationDeduplicator;
use RoutesPro\Support\Permissions;

if (!defined('ABSPATH')) { exit; }

class LocationsController {
    const NS = 'routespro/v1';

    public function register_routes(){
        register_rest_route(self::NS, '/locations', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'list_locations'],
                'permission_callback' => function(){ return current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front(); },
            ],
            [
                'methods'  => 'POST',
                'callback' => [$this, 'create_location'],
                'permission_callback' => function(){ return current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front(); },
            ],
        ]);
        register_rest_route(self::NS, '/locations/(?P<id>\d+)', [[
            'methods'  => 'GET',
            'callback' => [$this, 'get_location'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front(); },
        ]]);
    }

    public function list_locations(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';

        $client_id  = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        $q          = trim((string)$req->get_param('q'));
        $has_coords = absint($req->get_param('has_coords') ?: 0) ? 1 : 0;
        $bbox_raw   = trim((string)$req->get_param('bbox'));
        $order_raw  = sanitize_text_field($req->get_param('order') ?: 'recent');
        $order_sql  = ($order_raw === 'name') ? 'l.name ASC, l.id DESC' : 'l.id DESC';

        $per_page = max(1, min(1000, absint($req->get_param('per_page') ?: 1000)));
        $page     = max(1, absint($req->get_param('page') ?: 1));
        $offset   = ($page - 1) * $per_page;

        $where = ['1=1'];
        $args  = [];

        $scopeCheck = Permissions::assert_scope_or_error($client_id, $project_id);
        if (is_wp_error($scopeCheck)) return $scopeCheck;
        if ($client_id)  { $where[] = 'l.client_id=%d';  $args[] = $client_id; }
        if ($project_id) { $where[] = 'l.project_id=%d'; $args[] = $project_id; }
        list($scopeSql, $scopeArgs) = Permissions::scope_sql('l');
        if ($scopeSql !== '1=1') { $where[] = $scopeSql; $args = array_merge($args, $scopeArgs); }
        if ($has_coords) { $where[] = 'l.lat IS NOT NULL AND l.lng IS NOT NULL'; }

        if ($bbox_raw) {
            $parts = array_map('trim', explode(',', $bbox_raw));
            if (count($parts) === 4) {
                $lat1 = (float)$parts[0]; $lng1 = (float)$parts[1];
                $lat2 = (float)$parts[2]; $lng2 = (float)$parts[3];
                $minLat = min($lat1,$lat2); $maxLat = max($lat1,$lat2);
                $minLng = min($lng1,$lng2); $maxLng = max($lng1,$lng2);
                $where[] = '(l.lat BETWEEN %f AND %f AND l.lng BETWEEN %f AND %f)';
                array_push($args, $minLat, $maxLat, $minLng, $maxLng);
            }
        }

        if ($q !== '') {
            $like = '%'.$wpdb->esc_like($q).'%';
            $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.tags LIKE %s OR l.phone LIKE %s OR l.email LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }

        $where_sql = implode(' AND ', $where);

        $sql_total = "SELECT COUNT(*) FROM {$px}locations l WHERE {$where_sql}";
        $total = (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$args));

        $sql = "SELECT l.*, c.name AS category_name, sc.name AS subcategory_name
                FROM {$px}locations l
                LEFT JOIN {$px}categories c ON c.id=l.category_id
                LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id
                WHERE {$where_sql}
                ORDER BY {$order_sql}
                LIMIT %d OFFSET %d";
        $args_page = array_merge($args, [$per_page, $offset]);

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args_page), ARRAY_A);

        return new WP_REST_Response([
            'locations'   => $rows ?: [],
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $per_page ? (int)ceil($total / $per_page) : 1,
        ], 200);
    }

    public function get_location(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $id = absint($req['id']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT l.*, c.name AS category_name, sc.name AS subcategory_name FROM {$px}locations l LEFT JOIN {$px}categories c ON c.id=l.category_id LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id WHERE l.id=%d", $id), ARRAY_A);
        if (!$row) return new WP_Error('not_found', 'Local não encontrado.', ['status' => 404]);
        $scopeCheck = Permissions::assert_scope_or_error((int)($row['client_id'] ?? 0), (int)($row['project_id'] ?? 0));
        if (is_wp_error($scopeCheck)) return $scopeCheck;
        return new WP_REST_Response($row, 200);
    }

    public function create_location(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $p = $req->get_json_params() ?: [];

        $name    = sanitize_text_field($p['name'] ?? '');
        $address = sanitize_textarea_field($p['address'] ?? '');
        if ($name === '' && $address !== '') $name = $address;
        if ($name === '') return new WP_Error('bad_request', 'Nome ou morada obrigatórios.', ['status' => 400]);

        $lat = (isset($p['lat']) && is_numeric($p['lat'])) ? (float)$p['lat'] : null;
        $lng = (isset($p['lng']) && is_numeric($p['lng'])) ? (float)$p['lng'] : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) return new WP_Error('bad_request', 'Latitude inválida.', ['status' => 400]);
        if ($lng !== null && ($lng < -180 || $lng > 180)) return new WP_Error('bad_request', 'Longitude inválida.', ['status' => 400]);

        $client_id  = isset($p['client_id'])  ? absint($p['client_id'])  : 0;
        $project_id = isset($p['project_id']) ? absint($p['project_id']) : 0;
        $window_start = sanitize_text_field($p['window_start'] ?? '');
        $window_end   = sanitize_text_field($p['window_end'] ?? '');
        $service_time_min = isset($p['service_time_min']) ? max(0, intval($p['service_time_min'])) : 0;
        $tags         = sanitize_text_field($p['tags'] ?? '');
        $external_ref = sanitize_text_field($p['external_ref'] ?? '');
        $place_id     = sanitize_text_field($p['place_id'] ?? '');

        $scopeCheck = Permissions::assert_scope_or_error($client_id, $project_id);
        if (is_wp_error($scopeCheck)) return $scopeCheck;

        $replace_existing = !isset($p['replace_existing']) || absint($p['replace_existing']) === 1;

        $insert = [
            'client_id'        => $client_id ?: null,
            'project_id'       => $project_id ?: null,
            'name'             => $name,
            'address'          => $address,
            'lat'              => $lat,
            'lng'              => $lng,
            'window_start'     => $window_start ?: null,
            'window_end'       => $window_end ?: null,
            'service_time_min' => $service_time_min,
            'tags'             => $tags,
            'external_ref'     => $external_ref,
            'district'         => sanitize_text_field($p['district'] ?? ''),
            'county'           => sanitize_text_field($p['county'] ?? ''),
            'city'             => sanitize_text_field($p['city'] ?? ''),
            'parish'           => sanitize_text_field($p['parish'] ?? ''),
            'postal_code'      => sanitize_text_field($p['postal_code'] ?? ''),
            'country'          => sanitize_text_field($p['country'] ?? 'Portugal'),
            'category_id'      => absint($p['category_id'] ?? 0) ?: null,
            'subcategory_id'   => absint($p['subcategory_id'] ?? 0) ?: null,
            'contact_person'   => sanitize_text_field($p['contact_person'] ?? '') ?: $name,
            'phone'            => sanitize_text_field($p['phone'] ?? ''),
            'email'            => sanitize_email($p['email'] ?? ''),
            'website'          => esc_url_raw($p['website'] ?? ''),
            'place_id'         => $place_id,
            'source'           => sanitize_text_field($p['source'] ?? 'manual'),
            'source_confidence'=> isset($p['source_confidence']) ? max(0, min(100, intval($p['source_confidence']))) : 50,
            'location_type'    => sanitize_text_field($p['location_type'] ?? 'pdv'),
            'is_active'        => isset($p['is_active']) ? (absint($p['is_active']) ? 1 : 0) : 1,
            'is_validated'     => isset($p['is_validated']) ? (absint($p['is_validated']) ? 1 : 0) : 0,
            'last_seen_at'     => !empty($p['last_seen_at']) ? sanitize_text_field($p['last_seen_at']) : null,
        ];

        $result = LocationDeduplicator::upsert($insert, 0, $replace_existing);
        if (empty($result['id'])) return new WP_Error('db_error', $wpdb->last_error ?: 'DB insert falhou', ['status' => 500]);
        if (class_exists('\RoutesPro\Rest\CommercialController')) {
            \RoutesPro\Rest\CommercialController::bump_cache_version();
        }
        return new WP_REST_Response(['id' => (int)$result['id'], 'existing' => !empty($result['existing']), 'reason' => (string)($result['reason'] ?? '')], !empty($result['existing']) ? 200 : 201);
    }
}
