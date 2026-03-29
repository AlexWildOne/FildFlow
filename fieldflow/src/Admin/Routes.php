<?php
namespace RoutesPro\Admin;

use RoutesPro\Support\GeoPT;
use RoutesPro\Support\Permissions;
use RoutesPro\Services\LocationDeduplicator;

class Routes {
    public static function render() {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb; $px = $wpdb->prefix.'routespro_';

        $clients = $wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A);
        $sel_client_id = isset($_REQUEST['client_id']) ? absint($_REQUEST['client_id']) : 0;
        $projects = $sel_client_id
            ? $wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$px}projects WHERE client_id=%d ORDER BY name ASC", $sel_client_id), ARRAY_A)
            : $wpdb->get_results("SELECT id,name FROM {$px}projects ORDER BY name ASC LIMIT 200", ARRAY_A);

        if (!empty($_POST['routespro_routes_nonce']) && wp_verify_nonce($_POST['routespro_routes_nonce'],'routespro_routes')) {
            $id = absint($_POST['id'] ?? 0);
            $data = [
                'client_id'     => absint($_POST['client_id'] ?? 0),
                'project_id'    => ($_POST['project_id'] !== '' ? absint($_POST['project_id']) : null),
                'date'          => sanitize_text_field($_POST['date'] ?? date('Y-m-d')),
                'status'        => sanitize_text_field($_POST['status'] ?? 'draft'),
                'owner_user_id' => ($_POST['owner_user_id'] !== '' ? absint($_POST['owner_user_id']) : null),
            ];

            $commercial_meta = [
                'district' => sanitize_text_field($_POST['commercial_district'] ?? ''),
                'county' => sanitize_text_field($_POST['commercial_county'] ?? ''),
                'city' => sanitize_text_field($_POST['commercial_city'] ?? ''),
                'category_id' => absint($_POST['commercial_category_id'] ?? 0),
                'subcategory_id' => absint($_POST['commercial_subcategory_id'] ?? 0),
                'start_point' => [
                    'address' => sanitize_text_field($_POST['start_address'] ?? ''),
                    'lat' => (is_numeric($_POST['start_lat'] ?? null) ? (float) $_POST['start_lat'] : null),
                    'lng' => (is_numeric($_POST['start_lng'] ?? null) ? (float) $_POST['start_lng'] : null),
                ],
                'end_point' => [
                    'address' => sanitize_text_field($_POST['end_address'] ?? ''),
                    'lat' => (is_numeric($_POST['end_lat'] ?? null) ? (float) $_POST['end_lat'] : null),
                    'lng' => (is_numeric($_POST['end_lng'] ?? null) ? (float) $_POST['end_lng'] : null),
                ],
            ];
            $data['meta_json'] = wp_json_encode($commercial_meta);
            if (!empty($_POST['save_route_defaults'])) {
                $defs = get_option('routespro_route_defaults', []);
                if (!is_array($defs)) $defs = [];
                $key = ($data['client_id'] ?: 0) . '|' . ($data['project_id'] ?: 0) . '|' . ($data['owner_user_id'] ?: 0);
                $defs[$key] = [
                    'start_point' => $commercial_meta['start_point'],
                    'end_point' => $commercial_meta['end_point'],
                    'updated_at' => current_time('mysql'),
                ];
                update_option('routespro_route_defaults', $defs, false);
            }

            if ($id) {
                $wpdb->update($px.'routes', $data, ['id'=>$id]);
            } else {
                $wpdb->insert($px.'routes', $data);
                $id = (int) $wpdb->insert_id;
            }
            if ($id && !empty($data['owner_user_id'])) {
                \RoutesPro\Support\AssignmentMatrix::sync_route_owner_assignment((int)$id, (int)$data['owner_user_id'], 'owner');
            }

            $raw_points = wp_unslash($_POST['route_points_json'] ?? '[]');
            $points = json_decode($raw_points, true);
            if (!is_array($points)) $points = [];

            $wpdb->delete($px.'route_stops', ['route_id' => $id]);

            $seq = 0;
            foreach ($points as $point) {
                if (!is_array($point)) continue;
                $location_id = absint($point['location_id'] ?? 0);
                $name = sanitize_text_field($point['name'] ?? '');
                $address = sanitize_textarea_field($point['address'] ?? '');
                $place_id = sanitize_text_field($point['place_id'] ?? '');
                $phone = sanitize_text_field($point['phone'] ?? '');
                $email = sanitize_email($point['email'] ?? '');
                $contact_person = sanitize_text_field($point['contact_person'] ?? '');
                $district = sanitize_text_field($point['district'] ?? '');
                $county = sanitize_text_field($point['county'] ?? '');
                $city = sanitize_text_field($point['city'] ?? '');
                $category_id = absint($point['category_id'] ?? 0) ?: ($commercial_meta['category_id'] ?: null);
                $subcategory_id = absint($point['subcategory_id'] ?? 0) ?: ($commercial_meta['subcategory_id'] ?: null);
                $source = sanitize_text_field($point['source'] ?? 'route_capture');
                $visit_time_min = max(0, (int) ($point['visit_time_min'] ?? 0));
                $visit_time_mode = sanitize_text_field((string) ($point['visit_time_mode'] ?? ''));
                $lat = is_numeric($point['lat'] ?? null) ? (float) $point['lat'] : null;
                $lng = is_numeric($point['lng'] ?? null) ? (float) $point['lng'] : null;

                $locationPayload = [
                    'client_id' => $data['client_id'],
                    'project_id' => $data['project_id'],
                    'name' => $name !== '' ? $name : $address,
                    'address' => $address,
                    'district' => $district,
                    'county' => $county,
                    'city' => $city,
                    'category_id' => $category_id,
                    'subcategory_id' => $subcategory_id,
                    'contact_person' => $contact_person,
                    'phone' => $phone,
                    'email' => $email,
                    'place_id' => $place_id,
                    'lat' => $lat,
                    'lng' => $lng,
                    'source' => $source ?: 'route_capture',
                    'location_type' => 'pdv',
                    'is_active' => 1,
                ];

                if ($location_id) {
                    LocationDeduplicator::upsert($locationPayload, 0, true);
                } elseif ($name !== '' || $address !== '') {
                    $res = LocationDeduplicator::upsert($locationPayload, 0, true);
                    $location_id = (int)($res['id'] ?? 0);
                }

                if ($location_id) {
                    $wpdb->insert($px.'route_stops', ['route_id' => $id, 'location_id' => $location_id, 'seq' => $seq, 'meta_json' => wp_json_encode(['visit_time_min' => $visit_time_min, 'visit_time_mode' => $visit_time_mode])]);
                    $stop_id = (int) $wpdb->insert_id;
                    if (class_exists('\\RoutesPro\\Services\\RouteSnapshotService')) {
                        \RoutesPro\Services\RouteSnapshotService::capture($id, $stop_id ?: null, $location_id);
                    }
                    $seq++;
                }
            }

            echo '<div class="updated notice"><p>Rota guardada com sucesso.</p></div>';
        }

        if (!empty($_GET['delete'])) {
            $del = absint($_GET['delete']); check_admin_referer('routespro_routes_del_'.$del);
            $wpdb->delete($px.'routes', ['id'=>$del]);
            echo '<div class="updated notice"><p>Rota removida.</p></div>';
        }

        $edit = null;
        $route_meta = [];
        $route_points = [];
        if (!empty($_GET['edit'])) {
            $id = absint($_GET['edit']);
            $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}routes WHERE id=%d", $id), ARRAY_A);
            if ($edit) {
                $sel_client_id = (int)$edit['client_id'];
                $projects = $wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$px}projects WHERE client_id=%d ORDER BY name ASC", $sel_client_id), ARRAY_A);
                if (!empty($edit['meta_json'])) {
                    $decoded = json_decode((string)$edit['meta_json'], true);
                    if (is_array($decoded)) $route_meta = $decoded;
                }
                $route_points = $wpdb->get_results($wpdb->prepare(
                    "SELECT rs.seq, rs.meta_json AS stop_meta_json, l.id AS location_id, l.name, l.address, l.phone, l.email, l.contact_person, l.district, l.county, l.city, l.category_id, l.subcategory_id, l.place_id, l.lat, l.lng, l.source
                     FROM {$px}route_stops rs
                     INNER JOIN {$px}locations l ON l.id=rs.location_id
                     WHERE rs.route_id=%d ORDER BY rs.seq ASC, rs.id ASC", $id), ARRAY_A);
                if ($route_points) {
                    foreach ($route_points as &$route_point) {
                        $stop_meta = !empty($route_point['stop_meta_json']) ? json_decode((string) $route_point['stop_meta_json'], true) : [];
                        if (is_array($stop_meta)) {
                            $route_point['visit_time_min'] = max(0, (int) ($stop_meta['visit_time_min'] ?? 0));
                            $route_point['visit_time_mode'] = sanitize_text_field((string) ($stop_meta['visit_time_mode'] ?? ''));
                        }
                        unset($route_point['stop_meta_json']);
                    }
                    unset($route_point);
                }
            }
        }

        $route_default_key = ((int)($edit['client_id'] ?? $sel_client_id ?: 0)) . '|' . ((int)($edit['project_id'] ?? 0)) . '|' . ((int)($edit['owner_user_id'] ?? 0));
        $route_defaults = $routeDefaultsAll[$route_default_key] ?? [];
        $route_meta = array_replace_recursive($route_defaults, $route_meta);
        $startPoint = is_array($route_meta['start_point'] ?? null) ? $route_meta['start_point'] : ['address'=>'','lat'=>'','lng'=>''];
        $endPoint = is_array($route_meta['end_point'] ?? null) ? $route_meta['end_point'] : ['address'=>'','lat'=>'','lng'=>''];
        $selected_client_id = (int)($edit['client_id'] ?? $sel_client_id ?: 0);
        $selected_project_id = (int)($edit['project_id'] ?? 0);
        $users = Permissions::get_assignable_users($selected_client_id, $selected_project_id, ['ID','display_name','user_email','user_login']);
        $selected_owner_id = (int)($edit['owner_user_id'] ?? 0);
        if ($selected_owner_id && !array_filter($users, fn($u) => (int)$u->ID === $selected_owner_id)) {
            $owner_user = get_user_by('id', $selected_owner_id);
            if ($owner_user) $users[] = $owner_user;
        }
        $rows = $wpdb->get_results("SELECT r.*, c.name as client_name, p.name as project_name
            FROM {$px}routes r
            LEFT JOIN {$px}clients c ON c.id=r.client_id
            LEFT JOIN {$px}projects p ON p.id=r.project_id
            ORDER BY r.date DESC, r.id DESC
            LIMIT 500", ARRAY_A);

        $categories = $wpdb->get_results("SELECT id,name,parent_id FROM {$px}categories WHERE is_active=1 ORDER BY sort_order ASC, name ASC", ARRAY_A);
        $categorySeen = [];
        $categories = array_values(array_filter($categories, function($c) use (&$categorySeen){
            $key = strtolower(trim((string)($c['name'] ?? ''))) . '|' . (int)($c['parent_id'] ?? 0);
            if ($key === '|0') return false;
            if (isset($categorySeen[$key])) return false;
            $categorySeen[$key] = true;
            return true;
        }));
        $commercialRoots = array_values(array_filter($categories, fn($c) => empty($c['parent_id'])));
        $commercialChildren = array_values(array_filter($categories, fn($c) => !empty($c['parent_id'])));
        $commercialChildrenByParent = [];
        foreach ($commercialChildren as $child) {
            $parentId = (int) ($child['parent_id'] ?? 0);
            if (!$parentId) continue;
            $childName = trim((string) ($child['name'] ?? ''));
            if ($childName === '') continue;
            $childKey = sanitize_title($childName);
            if (!isset($commercialChildrenByParent[$parentId])) $commercialChildrenByParent[$parentId] = [];
            if (!isset($commercialChildrenByParent[$parentId][$childKey])) {
                $commercialChildrenByParent[$parentId][$childKey] = [
                    'id' => (int) ($child['id'] ?? 0),
                    'parent_id' => $parentId,
                    'name' => $childName,
                ];
            }
        }
        foreach ($commercialChildrenByParent as $parentId => $items) {
            uasort($items, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
            $commercialChildrenByParent[$parentId] = array_values($items);
        }
        $rpDistricts = GeoPT::districts();
        $rpCounties = GeoPT::all_counties();
        $rpCities = GeoPT::all_cities();
        $rpCountiesByDistrict = GeoPT::counties_by_district();
        $rpCitiesByDistrict = GeoPT::cities_by_district();
        $settings = Settings::get();
        $gmKey = trim((string)($settings['google_maps_key'] ?? ''));
        $mapsProvider = trim((string)($settings['maps_provider'] ?? 'leaflet'));
        $routeDefaultsAll = get_option('routespro_route_defaults', []);
        if (!is_array($routeDefaultsAll)) $routeDefaultsAll = [];
        $campaignAddressOptions = [];
        $projectAddresses = $wpdb->get_results("SELECT cl.project_id, l.name, l.address, l.lat, l.lng FROM {$px}campaign_locations cl INNER JOIN {$px}locations l ON l.id=cl.location_id WHERE cl.is_active=1 AND l.is_active=1 ORDER BY cl.project_id ASC, l.name ASC", ARRAY_A) ?: [];
        foreach ($projectAddresses as $addrRow) {
            $pid = (int) ($addrRow['project_id'] ?? 0);
            if (!$pid) continue;
            if (!isset($campaignAddressOptions[$pid])) $campaignAddressOptions[$pid] = [];
            $label = trim((string)($addrRow['name'] ?? ''));
            $address = trim((string)($addrRow['address'] ?? ''));
            $textAddr = trim($label . ($address ? ' · ' . $address : ''));
            if ($textAddr === '') continue;
            $campaignAddressOptions[$pid][] = ['label'=>$textAddr,'name'=>$label,'address'=>$address,'lat'=>$addrRow['lat'],'lng'=>$addrRow['lng']];
        }

        echo '<div class="wrap routespro-routes-premium">';
        Branding::render_header('Rotas', 'Descobre PDVs, adiciona-os à rota e calcula automaticamente a distância e o tempo, tudo no mesmo ecrã.');
        ?>
        <style>
          .routespro-routes-premium .rp-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(340px,.75fr);gap:20px;align-items:start;margin-top:16px}
          .routespro-routes-premium .rp-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 10px 30px rgba(15,23,42,.06);padding:22px}
          .routespro-routes-premium .rp-card h2,.routespro-routes-premium .rp-card h3{margin-top:0}
          .routespro-routes-premium .rp-note{color:#64748b;margin-top:0}
          .routespro-routes-premium .rp-chip{display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#f8fafc;border:1px solid #dbeafe;font-weight:600;margin:0 8px 8px 0}
          .routespro-routes-premium .rp-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px}
          .routespro-routes-premium .rp-mini-card{border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fff}
          .routespro-routes-premium .rp-result{border:1px solid #e5e7eb;border-radius:14px;padding:12px;background:#fff;margin-bottom:10px}
          .routespro-routes-premium .rp-result h4{margin:0 0 6px;font-size:14px}
          .routespro-routes-premium .rp-muted{color:#64748b}
          .routespro-routes-premium .rp-queue-item{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;margin-bottom:10px}
          .routespro-routes-premium .rp-queue-no{width:36px;height:36px;border-radius:999px;background:#111827;color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center}
          .routespro-routes-premium .rp-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;font-size:12px;font-weight:600;margin-right:6px}
          .routespro-routes-premium .rp-actions{display:flex;gap:8px;flex-wrap:wrap}
          .routespro-routes-premium .rp-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
          .routespro-routes-premium .rp-toolbar input,.routespro-routes-premium .rp-toolbar select,.routespro-routes-premium .rp-toolbar textarea{min-width:180px}
          .routespro-routes-premium .form-table th{width:220px;padding-top:16px}
          .routespro-routes-premium .form-table td{padding-top:10px;padding-bottom:10px}
          .routespro-routes-premium #rp-discovery-map,#rp-route-map{height:340px;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc}
          .routespro-routes-premium .rp-summary-grid{display:grid;grid-template-columns:repeat(3, minmax(0,1fr));gap:12px}
          .routespro-routes-premium .rp-summary-box{padding:14px;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc}
          .routespro-routes-premium .rp-summary-box strong{display:block;font-size:22px;color:#0f172a}
          .routespro-routes-premium .rp-inline-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}
          .routespro-routes-premium .rp-inline-form .full{grid-column:1 / -1}
          .routespro-routes-premium .rp-aside{position:sticky;top:24px}
          @media (max-width:1180px){.routespro-routes-premium .rp-grid,.routespro-routes-premium .rp-columns,.routespro-routes-premium .rp-inline-form,.routespro-routes-premium .rp-summary-grid{grid-template-columns:1fr}.routespro-routes-premium .rp-aside{position:static}}
        </style>
        <div>
          <span class="rp-chip">1. Filtrar mercado</span>
          <span class="rp-chip">2. Escolher ou criar PDVs</span>
          <span class="rp-chip">3. Adicionar à rota</span>
          <span class="rp-chip">4. Ver distância e tempo</span>
        </div>
        <form method="post" id="rp-route-form">
          <?php wp_nonce_field('routespro_routes','routespro_routes_nonce'); ?>
          <input type="hidden" name="id" value="<?php echo esc_attr($edit['id'] ?? 0); ?>">
          <input type="hidden" name="route_points_json" id="rp-route-points-json" value="<?php echo esc_attr(wp_json_encode($route_points)); ?>">
          <datalist id="rp-address-options"><?php foreach (($campaignAddressOptions[(int)($edit['project_id'] ?? 0)] ?? []) as $opt): ?><option value="<?php echo esc_attr($opt['label'] ?: $opt['address']); ?>" label="<?php echo esc_attr($opt['address']); ?>"></option><?php endforeach; ?></datalist>
          <div class="rp-grid">
            <div>
              <div class="rp-card">
                <h2><?php echo $edit ? 'Editar rota' : 'Nova rota'; ?></h2>
                <p class="rp-note">Escolhe a zona e a categoria, encontra PDVs existentes ou novos, adiciona-os à rota e deixa o planeamento alimentar-se automaticamente.</p>
                <table class="form-table" role="presentation">
                  <tr><th>Cliente</th><td><select name="client_id" id="rp-client" required><option value="">--</option><?php foreach($clients as $c): ?><option value="<?php echo intval($c['id']); ?>" <?php selected(($edit['client_id'] ?? $sel_client_id), $c['id']); ?>><?php echo esc_html($c['name']); ?></option><?php endforeach; ?></select><p class="description">O owner operacional passa a sincronizar automaticamente a visibilidade da rota no front e nas atribuições da própria rota.</p></td></tr>
                  <tr><th>Projeto</th><td><select name="project_id" id="rp-project"><option value="">--</option><?php foreach($projects as $p): ?><option value="<?php echo intval($p['id']); ?>" <?php selected(($edit['project_id'] ?? 0), $p['id']); ?>><?php echo esc_html($p['name']); ?></option><?php endforeach; ?></select></td></tr>
                  <tr><th>Data</th><td><input type="date" name="date" value="<?php echo esc_attr($edit['date'] ?? date('Y-m-d')); ?>"></td></tr>
                  <tr><th>Status</th><td><select name="status"><?php foreach(['draft','planned','in_progress','completed','canceled'] as $s): ?><option value="<?php echo esc_attr($s); ?>" <?php selected(($edit['status'] ?? 'draft'), $s); ?>><?php echo esc_html($s); ?></option><?php endforeach; ?></select></td></tr>
                  <tr><th>Owner operacional</th><td><select name="owner_user_id" id="rp-owner"><option value="">--</option><?php foreach($users as $u): ?><option value="<?php echo intval($u->ID); ?>" <?php selected(($edit['owner_user_id'] ?? 0), $u->ID); ?>><?php echo esc_html($u->display_name.' ['.$u->user_login.'] ('.$u->user_email.')'); ?></option><?php endforeach; ?></select></td></tr>
                  <tr><th>Ponto de partida</th><td><input type="text" name="start_address" id="rp-start-address" list="rp-address-options" value="<?php echo esc_attr($startPoint['address'] ?? ''); ?>" placeholder="Morada inicial, opcional" style="min-width:360px"><input type="hidden" name="start_lat" id="rp-start-lat" value="<?php echo esc_attr($startPoint['lat'] ?? ''); ?>"><input type="hidden" name="start_lng" id="rp-start-lng" value="<?php echo esc_attr($startPoint['lng'] ?? ''); ?>"><p class="description">Google autocomplete ou moradas já usadas na campanha.</p></td></tr>
                  <tr><th>Ponto de chegada</th><td><input type="text" name="end_address" id="rp-end-address" list="rp-address-options" value="<?php echo esc_attr($endPoint['address'] ?? ''); ?>" placeholder="Morada final, opcional" style="min-width:360px"><input type="hidden" name="end_lat" id="rp-end-lat" value="<?php echo esc_attr($endPoint['lat'] ?? ''); ?>"><input type="hidden" name="end_lng" id="rp-end-lng" value="<?php echo esc_attr($endPoint['lng'] ?? ''); ?>"><p class="description">Quando vazio, a rota começa e termina nos PDVs escolhidos.</p><label style="display:block;margin-top:8px"><input type="checkbox" name="save_route_defaults" value="1"> Fixar estes pontos por cliente/projeto/owner para novas rotas</label></td></tr>
                </table>
              </div>

              <div class="rp-card">
                <h3>Descobrir PDVs</h3>
                <div class="rp-toolbar">
                  <select name="commercial_district" id="rp-commercial-district"><option value="">Distrito</option><?php foreach($rpDistricts as $d): ?><option value="<?php echo esc_attr($d); ?>" <?php selected(($route_meta['district'] ?? ''), $d); ?>><?php echo esc_html($d); ?></option><?php endforeach; ?></select>
                  <select name="commercial_county" id="rp-commercial-county"><option value="">Concelho</option><?php foreach($rpCounties as $d): ?><option value="<?php echo esc_attr($d); ?>" <?php selected(($route_meta['county'] ?? ''), $d); ?>><?php echo esc_html($d); ?></option><?php endforeach; ?></select>
                  <select name="commercial_city" id="rp-commercial-city"><option value="">Cidade</option><?php foreach($rpCities as $d): ?><option value="<?php echo esc_attr($d); ?>" <?php selected(($route_meta['city'] ?? ''), $d); ?>><?php echo esc_html($d); ?></option><?php endforeach; ?></select>
                  <select name="commercial_category_id" id="rp-commercial-category"><option value="">Categoria</option><?php foreach($commercialRoots as $cat): ?><option value="<?php echo intval($cat['id']); ?>" <?php selected((int)($route_meta['category_id'] ?? 0), (int)$cat['id']); ?>><?php echo esc_html($cat['name']); ?></option><?php endforeach; ?></select>
                  <select name="commercial_subcategory_id" id="rp-commercial-subcategory"><option value="">Subcategoria</option></select>
                  <input type="search" id="rp-discovery-q" placeholder="Nome, morada ou referência">
                  <button type="button" class="button button-primary" id="rp-run-discovery">Procurar PDVs</button>
                </div>
                <p class="rp-note">Pesquisa interna e Google no mesmo fluxo. Em baixo podes adicionar diretamente à rota.</p><div id="rp-dedupe-notice" class="notice inline" style="display:none;margin:10px 0"></div>
                <div class="rp-columns">
                  <div class="rp-mini-card">
                    <h4 style="margin:0 0 10px">PDVs já existentes</h4>
                    <select id="rp-existing-select" style="width:100%;min-height:44px"><option value="">Escolhe um PDV existente</option></select>
                    <div id="rp-existing-results" style="margin-top:10px;max-height:260px;overflow:auto"></div>
                  </div>
                  <div class="rp-mini-card">
                    <h4 style="margin:0 0 10px">Novos PDVs encontrados</h4>
                    <div id="rp-google-results" style="max-height:310px;overflow:auto"></div>
                  </div>
                </div>
                <div style="margin-top:14px" id="rp-discovery-map-wrap">
                  <div id="rp-discovery-map"></div>
                </div>
              </div>

              <div class="rp-card">
                <h3>Novo PDV inline</h3>
                <p class="rp-note">Quando não existir um PDV adequado, cria-o aqui e adiciona-o de imediato à rota.</p>
                <div class="rp-inline-form">
                  <input type="text" id="rp-new-name" placeholder="Nome estabelecimento">
                  <input type="text" id="rp-new-phone" placeholder="Telefone">
                  <input type="text" id="rp-new-contact" placeholder="Contacto">
                  <input type="email" id="rp-new-email" placeholder="Email">
                  <input type="text" id="rp-new-address" class="full" placeholder="Morada">
                  <input type="text" id="rp-new-district" placeholder="Distrito">
                  <input type="text" id="rp-new-county" placeholder="Concelho">
                  <input type="text" id="rp-new-city" placeholder="Cidade">
                  <select id="rp-new-category"><option value="">Categoria</option><?php foreach($commercialRoots as $cat): ?><option value="<?php echo intval($cat['id']); ?>"><?php echo esc_html($cat['name']); ?></option><?php endforeach; ?></select>
                  <select id="rp-new-subcategory"><option value="">Subcategoria</option></select>
                  <input type="text" id="rp-new-lat" placeholder="Lat">
                  <input type="text" id="rp-new-lng" placeholder="Lng">
                  <input type="hidden" id="rp-new-place-id">
                </div>
                <p class="rp-note" id="rp-new-fill-note">Ao escolheres um novo PDV encontrado, os campos abaixo são preenchidos automaticamente para poderes classificar e adicionar à rota.</p>
                <div class="rp-actions" style="margin-top:12px">
                  <button type="button" class="button" id="rp-add-manual">Adicionar novo PDV à rota</button>
                </div>
              </div>
            </div>

            <div class="rp-aside">
              <div class="rp-card">
                <h3>PDVs adicionados à rota</h3>
                <p class="rp-note">A ordem desta lista é a ordem do percurso. O primeiro ponto é o início e o último é o fim.</p>
                <div id="rp-route-queue"></div>
                <div class="rp-actions">
                  <button type="button" class="button" id="rp-clear-queue">Limpar rota</button>
                </div>
              </div>

              <div class="rp-card">
                <h3>Planeador Google</h3>
                <p class="rp-note">O planeamento é alimentado automaticamente pelos PDVs adicionados acima. Aqui só vês o resultado, não precisas de preencher tudo outra vez.</p>
                <div class="rp-summary-grid">
                  <div class="rp-summary-box"><span>Total de PDVs</span><strong id="rp-summary-count">0</strong></div>
                  <div class="rp-summary-box"><span>Distância total</span><strong id="rp-summary-km">--</strong></div>
                  <div class="rp-summary-box"><span>Tempo viagem</span><strong id="rp-summary-travel-time">--</strong></div>
                  <div class="rp-summary-box"><span>Tempo visita</span><strong id="rp-summary-visit-time">0m</strong></div>
                  <div class="rp-summary-box"><span>Tempo total</span><strong id="rp-summary-time">--</strong></div>
                </div>
                <p class="rp-note" style="margin-top:10px">Tempo perdido na visita é opcional. Podes definir manualmente ou escolher buckets de 15 minutos até 6 horas.</p>
                <div style="margin-top:14px" id="rp-route-map"></div>
                <div id="rp-route-legs" style="margin-top:12px;max-height:260px;overflow:auto"></div>
              </div>

              <div class="rp-card">
                <div class="rp-actions">
                  <button class="button button-primary button-large">Guardar rota</button>
                  <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=routespro-routes&client_id='.(int)$sel_client_id)); ?>">Nova rota</a><?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </form>

        <div class="rp-card" style="margin-top:20px">
          <h3>Rotas existentes</h3>
          <table class="widefat striped">
            <thead><tr><th>ID</th><th>Data</th><th>Cliente</th><th>Projeto</th><th>Status</th><th>Owner</th><th></th></tr></thead>
            <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td>#<?php echo intval($r['id']); ?></td>
                <td><?php echo esc_html($r['date']); ?></td>
                <td><?php echo esc_html($r['client_name'] ?: '-'); ?></td>
                <td><?php echo esc_html($r['project_name'] ?: '-'); ?></td>
                <td><?php echo esc_html($r['status']); ?></td>
                <td><?php echo esc_html(get_the_author_meta('display_name', (int)($r['owner_user_id'] ?? 0)) ?: '-'); ?></td>
                <td>
                  <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=routespro-routes&edit='.(int)$r['id'].'&client_id='.(int)$r['client_id'])); ?>">Editar</a>
                  <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=routespro-routes&delete='.(int)$r['id']), 'routespro_routes_del_'.(int)$r['id'])); ?>" onclick="return confirm('Remover esta rota?')">Apagar</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function(){
          const api = <?php echo wp_json_encode(rest_url('routespro/v1/')); ?>;
          const nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
          const routeDefaults = <?php echo wp_json_encode($routeDefaultsAll); ?>;
          const campaignAddressOptions = <?php echo wp_json_encode($campaignAddressOptions); ?>;
          const ownerAjaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
          const mapsProvider = <?php echo wp_json_encode($mapsProvider); ?>;
          const mapsKey = <?php echo wp_json_encode($gmKey); ?>;
          const geoCounties = <?php echo wp_json_encode($rpCountiesByDistrict); ?>;
          const geoCities = <?php echo wp_json_encode($rpCitiesByDistrict); ?>;
          const subcatEl = document.getElementById('rp-commercial-subcategory');
          const catEl = document.getElementById('rp-commercial-category');
          const subcatsByParent = <?php echo wp_json_encode($commercialChildrenByParent); ?>;
          const initialSubcategoryId = <?php echo (int)($route_meta['subcategory_id'] ?? 0); ?>;
          const districtEl = document.getElementById('rp-commercial-district');
          const countyEl = document.getElementById('rp-commercial-county');
          const cityEl = document.getElementById('rp-commercial-city');
          const routePointsInput = document.getElementById('rp-route-points-json');
          let routePoints = [];
          try { routePoints = JSON.parse(routePointsInput.value || '[]') || []; } catch(e) { routePoints = []; }
          routePoints = routePoints.map(function(p){ return normalizePoint(p); });
          let discoveryMap = null, routeMap = null, routeDirections = null, discoveryMarkers = [], routeMarkers = [];
          let lastExistingItems = [], lastGoogleItems = [];
          let existingMapItems = [];

          function normalizePoint(p){
            return {
              location_id: parseInt(p.location_id || p.id || 0, 10) || 0,
              name: p.name || p.location_name || '',
              address: p.address || '',
              phone: p.phone || '',
              email: p.email || '',
              contact_person: p.contact_person || '',
              district: p.district || '',
              county: p.county || '',
              city: p.city || '',
              visit_time_min: parseInt(p.visit_time_min || 0, 10) || 0,
              visit_time_mode: p.visit_time_mode || '',
              category_id: parseInt(p.category_id || 0, 10) || 0,
              subcategory_id: parseInt(p.subcategory_id || 0, 10) || 0,
              place_id: p.place_id || '',
              lat: toNum(p.lat),
              lng: toNum(p.lng),
              source: p.source || (p.location_id ? 'existing' : 'route_capture')
            };
          }
          function toNum(v){ const n = parseFloat(v); return Number.isFinite(n) ? n : null; }
          function escapeHtml(s){ return String(s || '').replace(/[&<>\"]/g, function(ch){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[ch]; }); }
          function updateHidden(){ routePointsInput.value = JSON.stringify(routePoints); }
          function formatMinutes(totalMinutes){
            const mins = Math.max(0, parseInt(totalMinutes || 0, 10) || 0);
            const h = Math.floor(mins / 60);
            const m = mins % 60;
            if (h && m) return h + 'h ' + m + 'm';
            if (h) return h + 'h';
            return m + 'm';
          }
          function visitOptionsHtml(selected){
            const current = Math.max(0, parseInt(selected || 0, 10) || 0);
            const out = ['<option value="">Sem tempo de visita</option>'];
            for (let mins = 15; mins <= 360; mins += 15) {
              out.push('<option value="'+mins+'"'+(mins===current?' selected':'')+'>'+formatMinutes(mins)+'</option>');
            }
            out.push('<option value="manual"'+((current>0 && current % 15 !== 0)?' selected':'')+'>Manual</option>');
            return out.join('');
          }
          function filterSubcats(){
            const pid = String(catEl.value || '');
            const current = String(subcatEl.value || '');
            const items = pid && subcatsByParent[pid] ? subcatsByParent[pid] : [];
            const seen = new Set();
            const options = ['<option value="">Subcategoria</option>'];
            items.forEach(function(item){
              const name = String(item && item.name || '').trim();
              const key = name.toLocaleLowerCase('pt-PT');
              if (!name || seen.has(key)) return;
              seen.add(key);
              const id = parseInt(item.id || 0, 10) || 0;
              options.push('<option value="'+id+'"'+((String(id) === current || (!current && String(id) === String(initialSubcategoryId))) ? ' selected' : '')+'>'+escapeHtml(name)+'</option>');
            });
            subcatEl.innerHTML = options.join('');
            if (current && !items.some(function(item){ return String(item.id) === current; })) {
              subcatEl.value = '';
            }
          }
          function uniqueList(items){ return Array.from(new Set((items || []).filter(Boolean))).sort((a,b)=>String(a).localeCompare(String(b), 'pt')); }
          function repopulate(selectEl, items, selected, placeholder){
            const opts = ['<option value="">'+placeholder+'</option>'].concat((items || []).map(function(item){
              const safe = String(item).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
              return '<option value="'+safe+'"'+(item===selected?' selected':'')+'>'+safe+'</option>';
            }));
            selectEl.innerHTML = opts.join('');
          }
          function dedupeLocationItems(items){
            const seen = new Map();
            (items || []).forEach(function(raw){
              const item = normalizePoint(raw || {});
              const key = item.location_id
                ? ('id:' + item.location_id)
                : (item.place_id
                    ? ('place:' + item.place_id)
                    : ('nameaddr:' + String(item.name || '').trim().toLowerCase() + '|' + String(item.address || '').trim().toLowerCase()));
              if (!seen.has(key)) {
                seen.set(key, Object.assign({}, raw, item));
                return;
              }
              const prev = seen.get(key) || {};
              const score = function(v){
                let s = 0;
                if (v.phone) s += 2;
                if (v.email) s += 2;
                if (v.contact_person) s += 1;
                if (Number.isFinite(parseFloat(v.lat)) && Number.isFinite(parseFloat(v.lng))) s += 2;
                if (v.address) s += 1;
                return s;
              };
              if (score(item) >= score(prev)) seen.set(key, Object.assign({}, prev, raw, item));
            });
            return Array.from(seen.values());
          }
          function syncGeo(){
            const district = districtEl.value || '';
            const selectedCounty = countyEl.value || '';
            const selectedCity = cityEl.value || '';
            const allCounties = uniqueList(Object.values(geoCounties).flat());
            const allCities = uniqueList(Object.values(geoCities).flat());
            repopulate(countyEl, district ? (geoCounties[district] || []) : allCounties, selectedCounty, 'Concelho');
            repopulate(cityEl, district ? (geoCities[district] || []) : allCities, selectedCity, 'Cidade');
          }
          filterSubcats();
          catEl.addEventListener('change', filterSubcats);
          districtEl.addEventListener('change', syncGeo);
          syncGeo();

          async function reloadProjectsForClient(){
            const clientId = document.getElementById('rp-client').value || '';
            const projectEl = document.getElementById('rp-project');
            if(!clientId){ projectEl.innerHTML = '<option value="">--</option>'; return; }
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const res = await fetch(ajaxUrl + '?action=routespro_projects_for_client&client_id=' + encodeURIComponent(clientId), {credentials:'same-origin'});
            if(!res.ok) return;
            const items = await res.json();
            const current = projectEl.value || '';
            projectEl.innerHTML = ['<option value="">--</option>'].concat((items||[]).map(function(p){ return '<option value="'+String(p.id)+'">'+String(p.name)+'</option>'; })).join('');
            if(current) projectEl.value = current;
          }
          document.getElementById('rp-client').addEventListener('change', reloadProjectsForClient);

          function currentSearchParams(){
            const p = new URLSearchParams();
            if (districtEl.value) p.set('district', districtEl.value);
            if (countyEl.value) p.set('county', countyEl.value);
            if (cityEl.value) p.set('city', cityEl.value);
            if (catEl.value) p.set('category_id', catEl.value);
            if (subcatEl.value) p.set('subcategory_id', subcatEl.value);
            const q = document.getElementById('rp-discovery-q').value || '';
            if (q) p.set('q', q);
            return p;
          }

          function samePoint(a,b){
            if (a.location_id && b.location_id && String(a.location_id) === String(b.location_id)) return true;
            if (a.place_id && b.place_id && a.place_id === b.place_id) return true;
            return !!a.name && !!a.address && a.name === b.name && a.address === b.address;
          }
          function normalizePhone(v){ return String(v || '').replace(/\D+/g, ''); }
          function showDedupeNotice(message, type){
            const el = document.getElementById('rp-dedupe-notice');
            if (!el) return;
            el.style.display = 'block';
            el.className = 'notice inline ' + ((type === 'warning') ? 'notice-warning' : 'notice-info');
            el.innerHTML = '<p style="margin:8px 0">' + escapeHtml(message) + '</p>';
          }
          function clearDedupeNotice(){ const el = document.getElementById('rp-dedupe-notice'); if (el) { el.style.display = 'none'; el.innerHTML=''; } }
          function findDuplicateCandidate(point){
            const p = normalizePoint(point);
            const pool = [].concat(lastExistingItems || [], existingMapItems || []);
            for (const item of pool) {
              const candidate = normalizePoint(item);
              if (samePoint(candidate, p)) return {item:candidate, reason:'nome e morada'};
              if (candidate.place_id && p.place_id && candidate.place_id === p.place_id) return {item:candidate, reason:'place_id'};
              const cp = normalizePhone(candidate.phone), pp = normalizePhone(p.phone);
              if (cp && pp && cp === pp) return {item:candidate, reason:'telefone'};
              if (candidate.email && p.email && String(candidate.email).toLowerCase() === String(p.email).toLowerCase()) return {item:candidate, reason:'email'};
            }
            return null;
          }
          function addPointToRoute(point){
            const n = normalizePoint(point);
            if (!n.name && !n.address) return;
            if (catEl.value && !n.category_id) n.category_id = parseInt(catEl.value, 10) || 0;
            if (subcatEl.value && !n.subcategory_id) n.subcategory_id = parseInt(subcatEl.value, 10) || 0;
            if (!n.district) n.district = districtEl.value || '';
            if (!n.county) n.county = countyEl.value || '';
            if (!n.city) n.city = cityEl.value || '';
            const dup = findDuplicateCandidate(n);
            if (dup && !n.location_id) {
              n.location_id = parseInt(dup.item.location_id || dup.item.id || 0, 10) || 0;
              n.source = 'existing';
              n.place_id = n.place_id || dup.item.place_id || '';
              n.phone = n.phone || dup.item.phone || '';
              n.email = n.email || dup.item.email || '';
              n.contact_person = n.contact_person || dup.item.contact_person || '';
              showDedupeNotice('Este PDV já existe na base. Foi associado ao registo existente por ' + dup.reason + '. Se alterares os dados e guardares a rota, o último save substitui o registo existente.', 'warning');
            } else {
              clearDedupeNotice();
            }
            if (routePoints.some(function(existing){ return samePoint(existing, n); })) return;
            routePoints.push(n);
            updateHidden();
            renderQueue();
            renderPlanner();
          }

          function populateInlineFromPoint(point){
            const n = normalizePoint(point || {});
            document.getElementById('rp-new-name').value = n.name || '';
            document.getElementById('rp-new-phone').value = n.phone || '';
            document.getElementById('rp-new-contact').value = n.contact_person || '';
            document.getElementById('rp-new-email').value = n.email || '';
            document.getElementById('rp-new-address').value = n.address || '';
            document.getElementById('rp-new-district').value = n.district || districtEl.value || '';
            document.getElementById('rp-new-county').value = n.county || countyEl.value || '';
            document.getElementById('rp-new-city').value = n.city || cityEl.value || '';
            document.getElementById('rp-new-lat').value = n.lat ?? '';
            document.getElementById('rp-new-lng').value = n.lng ?? '';
            const placeIdEl = document.getElementById('rp-new-place-id');
            if (placeIdEl) placeIdEl.value = n.place_id || '';
            if (newCatEl) {
              newCatEl.value = String(n.category_id || catEl.value || '');
              refreshInlineSubcategories();
            }
            if (newSubcatEl) {
              newSubcatEl.value = String(n.subcategory_id || subcatEl.value || '');
            }
            const note = document.getElementById('rp-new-fill-note');
            if (note) note.textContent = 'PDV preparado a partir da descoberta. Revê categoria, subcategoria e dados antes de adicionares à rota.';
          }

          function cardHtml(item, source){
            const meta = [item.category_name || '', item.city || '', item.phone || ''].filter(Boolean).join(' • ');
            const buttonText = source === 'google' ? 'Usar no formulário' : 'Adicionar à rota';
            return '<div class="rp-result"><h4>'+escapeHtml(item.name || 'PDV')+'</h4><div class="rp-muted">'+escapeHtml(item.address || '')+'</div>'+
              (meta ? '<div style="font-size:12px;margin:6px 0;color:#475569">'+escapeHtml(meta)+'</div>' : '')+
              '<div class="rp-actions"><button type="button" class="button button-small rp-add-point" data-source="'+escapeHtml(source)+'">'+buttonText+'</button></div></div>';
          }

          async function runExistingLookup(){
            const params = currentSearchParams();
            params.set('per_page','100');
            params.set('only_active','1');
            const wrap = document.getElementById('rp-existing-results');
            const select = document.getElementById('rp-existing-select');
            const res = await fetch(api + 'commercial-search?' + params.toString(), {credentials:'same-origin', headers:{'X-WP-Nonce': nonce}});
            if (!res.ok) { wrap.innerHTML = '<em>Falha a carregar PDVs existentes.</em>'; select.innerHTML = '<option value="">Escolhe um PDV existente</option>'; lastExistingItems = []; existingMapItems = []; return []; }
            const data = await res.json();
            const items = dedupeLocationItems((data.items || []).map(function(item){
              item.text = [item.name || '', item.city || '', item.address || ''].filter(Boolean).join(' | ');
              return item;
            }));
            lastExistingItems = items;
            existingMapItems = items.filter(function(item){ return Number.isFinite(parseFloat(item.lat)) && Number.isFinite(parseFloat(item.lng)); });
            select.innerHTML = '<option value="">Escolhe um PDV existente</option>' + items.map(function(item, idx){ return '<option value="'+idx+'">'+escapeHtml(item.text || item.name || 'PDV')+'</option>'; }).join('');
            select.onchange = function(){
              const idx = parseInt(this.value, 10);
              if (Number.isFinite(idx) && items[idx]) {
                populateInlineFromPoint(items[idx]);
                addPointToRoute(items[idx]);
              }
            };
            wrap.innerHTML = items.length ? items.map(function(item){ return cardHtml(item, 'existing'); }).join('') : '<em>Sem PDVs existentes para este filtro.</em>';
            wrap.querySelectorAll('.rp-add-point').forEach(function(btn, idx){ btn.addEventListener('click', function(){ populateInlineFromPoint(items[idx]); addPointToRoute(items[idx]); }); });
            return items;
          }

          let geocoder = null, placesService = null;
          function setupGooglePlaces(){
            if (!(window.google && google.maps && google.maps.places)) return false;
            if (!geocoder) geocoder = new google.maps.Geocoder();
            if (!placesService) placesService = new google.maps.places.PlacesService(document.createElement('div'));
            return true;
          }
          function currentZoneQuery(){
            return [cityEl && cityEl.value, countyEl && countyEl.value, districtEl && districtEl.value, 'Portugal'].filter(Boolean).join(', ');
          }
          function currentKeyword(){
            const cat = catEl && catEl.selectedOptions && catEl.selectedOptions[0] ? catEl.selectedOptions[0].textContent.trim() : '';
            const sub = subcatEl && subcatEl.selectedOptions && subcatEl.selectedOptions[0] ? subcatEl.selectedOptions[0].textContent.trim() : '';
            const q = document.getElementById('rp-discovery-q').value || '';
            return [sub || cat, q].filter(Boolean).join(' ').trim() || 'estabelecimentos';
          }
          function googleTypeFromText(text){
            const t = String(text || '').toLowerCase();
            if (t.includes('hotel')) return 'lodging';
            if (t.includes('rest')) return 'restaurant';
            if (t.includes('cafe') || t.includes('café')) return 'cafe';
            if (t.includes('bar')) return 'bar';
            if (t.includes('super')) return 'supermarket';
            if (t.includes('loja') || t.includes('retalho') || t.includes('store')) return 'store';
            if (t.includes('farm')) return 'pharmacy';
            return '';
          }
          function geocodeAddress(address){
            return new Promise(function(resolve, reject){
              if (!setupGooglePlaces()) return reject(new Error('Google Maps Places não está disponível.'));
              geocoder.geocode({address: address}, function(results, status){
                if (status !== 'OK' || !results || !results[0]) return reject(new Error('Não foi possível localizar a zona selecionada.'));
                resolve(results[0]);
              });
            });
          }
          function pagedSearch(methodName, request){
            return new Promise(function(resolve, reject){
              if (!setupGooglePlaces()) return reject(new Error('Google Maps Places não está disponível.'));
              const out = [];
              const handler = function(results, status, pagination){
                if (status !== google.maps.places.PlacesServiceStatus.OK && status !== google.maps.places.PlacesServiceStatus.ZERO_RESULTS) return reject(new Error('Google Places devolveu: ' + status));
                out.push(...(results || []));
                if (pagination && pagination.hasNextPage && out.length < 60) {
                  window.setTimeout(function(){ pagination.nextPage(); }, 2200);
                  return;
                }
                resolve(out);
              };
              if (methodName === 'textSearch') placesService.textSearch(request, handler);
              else placesService.nearbySearch(request, handler);
            });
          }
          function getPlaceDetails(placeId){
            return new Promise(function(resolve){
              if (!placeId || !setupGooglePlaces()) return resolve({});
              placesService.getDetails({placeId: placeId, fields:['formatted_phone_number','international_phone_number','website','name','formatted_address','address_components','geometry','place_id']}, function(result, status){
                if (status !== google.maps.places.PlacesServiceStatus.OK || !result) return resolve({});
                resolve(result);
              });
            });
          }
          function parseAddressComponents(components){
            const out = {district:'', county:'', city:''};
            (components || []).forEach(function(c){
              const types = c.types || [];
              if (!out.city && (types.includes('locality') || types.includes('postal_town'))) out.city = c.long_name || '';
              if (!out.county && types.includes('administrative_area_level_2')) out.county = c.long_name || '';
              if (!out.district && types.includes('administrative_area_level_1')) out.district = c.long_name || '';
            });
            return out;
          }
          async function runGoogleDiscovery(){
            const wrap = document.getElementById('rp-google-results');
            if (!setupGooglePlaces()) {
              wrap.innerHTML = '<em>Ativa Google Maps com Places nas Settings para descobrir novos PDVs.</em>';
              lastGoogleItems = [];
              drawDiscoveryMarkers(existingMapItems, []);
              return [];
            }
            const zone = currentZoneQuery();
            if (!zone) {
              wrap.innerHTML = '<em>Escolhe primeiro Distrito, Concelho ou Cidade.</em>';
              lastGoogleItems = [];
              drawDiscoveryMarkers(existingMapItems, []);
              return [];
            }
            try {
              const geo = await geocodeAddress(zone);
              const center = geo.geometry.location;
              const label = currentKeyword();
              const googleType = googleTypeFromText(label);
              const radius = cityEl && cityEl.value ? 15000 : (countyEl && countyEl.value ? 35000 : 80000);
              const textRequest = {query: label + ' em ' + zone, location: center, radius: radius};
              const nearbyRequest = {location: center, radius: radius, keyword: label};
              if (googleType) nearbyRequest.type = googleType;
              const textResults = await pagedSearch('textSearch', textRequest).catch(function(){ return []; });
              const nearbyResults = await pagedSearch('nearbySearch', nearbyRequest).catch(function(){ return []; });
              const merged = new Map();
              [].concat(textResults || [], nearbyResults || []).forEach(function(item){
                const key = item.place_id || ((item.name || '') + '|' + (item.formatted_address || item.vicinity || ''));
                if (!merged.has(key)) merged.set(key, item);
              });
              const items = [];
              for (const item of Array.from(merged.values()).slice(0, 60)) {
                const details = await getPlaceDetails(item.place_id);
                const parsed = parseAddressComponents((details && details.address_components) || item.address_components || []);
                const geometry = (details && details.geometry) || item.geometry || null;
                const loc = geometry && geometry.location ? geometry.location : null;
                items.push({
                  name: (details && details.name) || item.name || '',
                  address: (details && details.formatted_address) || item.formatted_address || item.vicinity || '',
                  district: parsed.district || (districtEl.value || ''),
                  county: parsed.county || (countyEl.value || ''),
                  city: parsed.city || (cityEl.value || ''),
                  lat: loc ? (typeof loc.lat === 'function' ? loc.lat() : loc.lat) : null,
                  lng: loc ? (typeof loc.lng === 'function' ? loc.lng() : loc.lng) : null,
                  place_id: item.place_id || (details && details.place_id) || '',
                  phone: (details && (details.international_phone_number || details.formatted_phone_number)) || item.formatted_phone_number || '',
                  email: '',
                  contact_person: '',
                  source: 'google'
                });
              }
              const deduped = dedupeLocationItems(Array.from(new Map(items.map(function(item){ return [item.place_id || ((item.name || '') + '|' + (item.address || '')), item]; })).values()));
              lastGoogleItems = deduped;
              wrap.innerHTML = deduped.length ? deduped.map(function(item){ return cardHtml(item, 'google'); }).join('') : '<em>Sem novos PDVs encontrados no Google para este filtro.</em>';
              wrap.querySelectorAll('.rp-add-point').forEach(function(btn, idx){ btn.addEventListener('click', function(){ addPointToRoute(deduped[idx]); }); });
              drawDiscoveryMarkers(existingMapItems, deduped);
              return deduped;
            } catch(err) {
              wrap.innerHTML = '<em>' + escapeHtml(err && err.message ? err.message : 'A descoberta Google falhou.') + '</em>';
              lastGoogleItems = [];
              drawDiscoveryMarkers(existingMapItems, []);
              return [];
            }
          }

          async function runDiscovery(){
            const btn = document.getElementById('rp-run-discovery');
            btn.disabled = true;
            btn.textContent = 'A procurar...';
            try {
              const existing = await runExistingLookup();
              drawDiscoveryMarkers(existingMapItems, []);
              await runGoogleDiscovery();
            }
            finally {
              btn.disabled = false;
              btn.textContent = 'Procurar PDVs';
            }
          }
          document.getElementById('rp-run-discovery').addEventListener('click', runDiscovery);
          document.getElementById('rp-discovery-q').addEventListener('keydown', function(ev){ if (ev.key === 'Enter') { ev.preventDefault(); runDiscovery(); } });

          const newCatEl = document.getElementById('rp-new-category');
          const newSubcatEl = document.getElementById('rp-new-subcategory');
          function refreshInlineSubcategories(){
            if (!newSubcatEl) return;
            const pid = parseInt((newCatEl && newCatEl.value) || '0', 10) || 0;
            const options = ['<option value="">Subcategoria</option>'];
            (subcatsByParent[String(pid)] || subcatsByParent[pid] || []).forEach(function(item){ options.push('<option value="'+String(item.id)+'">'+escapeHtml(item.name || '')+'</option>'); });
            newSubcatEl.innerHTML = options.join('');
          }
          if (newCatEl) newCatEl.addEventListener('change', refreshInlineSubcategories);
          refreshInlineSubcategories();
          document.getElementById('rp-add-manual').addEventListener('click', function(){
            addPointToRoute({
              name: document.getElementById('rp-new-name').value || '',
              address: document.getElementById('rp-new-address').value || '',
              phone: document.getElementById('rp-new-phone').value || '',
              contact_person: document.getElementById('rp-new-contact').value || '',
              email: document.getElementById('rp-new-email').value || '',
              district: document.getElementById('rp-new-district').value || '',
              county: document.getElementById('rp-new-county').value || '',
              city: document.getElementById('rp-new-city').value || '',
              category_id: parseInt((newCatEl && newCatEl.value) || '0', 10) || 0,
              subcategory_id: parseInt((newSubcatEl && newSubcatEl.value) || '0', 10) || 0,
              lat: document.getElementById('rp-new-lat').value || '',
              lng: document.getElementById('rp-new-lng').value || '',
              place_id: document.getElementById('rp-new-place-id').value || '',
              source: 'route_capture'
            });
          });
          document.getElementById('rp-clear-queue').addEventListener('click', function(){ routePoints = []; updateHidden(); renderQueue(); renderPlanner(); });

          function renderQueue(){
            const wrap = document.getElementById('rp-route-queue');
            const visitSummaryEl = document.getElementById('rp-summary-visit-time');
            const totalVisitMin = routePoints.reduce(function(sum, point){ return sum + (parseInt(point.visit_time_min || 0, 10) || 0); }, 0);
            if (visitSummaryEl) visitSummaryEl.textContent = formatMinutes(totalVisitMin);
            if (!routePoints.length) {
              wrap.innerHTML = '<em>Ainda não adicionaste PDVs à rota.</em>';
              document.getElementById('rp-summary-count').textContent = '0';
              return;
            }
            wrap.innerHTML = routePoints.map(function(item, idx){
              const role = idx === 0 ? 'Início' : (idx === routePoints.length - 1 ? 'Fim' : 'Paragem');
              const currentVisit = parseInt(item.visit_time_min || 0, 10) || 0;
              const showManual = currentVisit > 0 && currentVisit % 15 !== 0;
              return '<div class="rp-queue-item">'+
                '<div class="rp-queue-no">'+(idx+1)+'</div>'+
                '<div><span class="rp-badge">'+escapeHtml(role)+'</span><span class="rp-badge">'+escapeHtml(item.source || 'pdv')+'</span><div style="font-weight:700;color:#0f172a">'+escapeHtml(item.name || item.address || 'PDV')+'</div><div class="rp-muted">'+escapeHtml(item.address || '')+'</div><div style="font-size:12px;color:#475569;margin-top:4px">'+escapeHtml([item.phone || '', item.email || '', item.contact_person || ''].filter(Boolean).join(' • '))+'</div>'+
                '<div style="margin-top:10px;display:grid;grid-template-columns:minmax(0,1fr) 120px;gap:8px;align-items:center">'+
                  '<select data-visit-select="'+idx+'">'+visitOptionsHtml(currentVisit)+'</select>'+
                  '<input type="number" min="1" max="360" step="1" data-visit-manual="'+idx+'" value="'+(showManual ? currentVisit : '')+'" placeholder="Minutos" style="display:'+(showManual ? 'block' : 'none')+'">'+
                '</div><div class="rp-muted" style="margin-top:6px">Tempo visita: '+escapeHtml(currentVisit ? formatMinutes(currentVisit) : 'não definido')+'</div></div>'+
                '<div class="rp-actions"><button type="button" class="button button-small" data-up="'+idx+'">↑</button><button type="button" class="button button-small" data-down="'+idx+'">↓</button><button type="button" class="button button-small" data-remove="'+idx+'">Remover</button></div>'+
              '</div>';
            }).join('');
            document.getElementById('rp-summary-count').textContent = String(routePoints.length);
            wrap.querySelectorAll('[data-visit-select]').forEach(function(select){
              select.addEventListener('change', function(){
                const idx = parseInt(select.dataset.visitSelect, 10);
                if (!Number.isFinite(idx) || !routePoints[idx]) return;
                const manualInput = wrap.querySelector('[data-visit-manual="'+idx+'"]');
                if (select.value === 'manual') {
                  routePoints[idx].visit_time_mode = 'manual';
                  if (manualInput) manualInput.style.display = 'block';
                  const manualVal = parseInt((manualInput && manualInput.value) || routePoints[idx].visit_time_min || 0, 10) || 0;
                  routePoints[idx].visit_time_min = manualVal;
                } else {
                  routePoints[idx].visit_time_mode = select.value ? 'bucket' : '';
                  routePoints[idx].visit_time_min = parseInt(select.value || 0, 10) || 0;
                  if (manualInput) { manualInput.style.display = 'none'; manualInput.value = ''; }
                }
                updateHidden();
                renderQueue();
                renderPlanner();
              });
            });
            wrap.querySelectorAll('[data-visit-manual]').forEach(function(input){
              const applyManual = function(){
                const idx = parseInt(input.dataset.visitManual, 10);
                if (!Number.isFinite(idx) || !routePoints[idx]) return;
                const val = Math.max(0, Math.min(360, parseInt(input.value || 0, 10) || 0));
                routePoints[idx].visit_time_mode = val ? 'manual' : '';
                routePoints[idx].visit_time_min = val;
                updateHidden();
                renderQueue();
                renderPlanner();
              };
              input.addEventListener('change', applyManual);
              input.addEventListener('blur', applyManual);
            });
            wrap.querySelectorAll('[data-up]').forEach(function(btn){ btn.addEventListener('click', function(){ const idx = parseInt(btn.dataset.up,10); if (idx>0){ const tmp = routePoints[idx-1]; routePoints[idx-1]=routePoints[idx]; routePoints[idx]=tmp; updateHidden(); renderQueue(); renderPlanner(); } }); });
            wrap.querySelectorAll('[data-down]').forEach(function(btn){ btn.addEventListener('click', function(){ const idx = parseInt(btn.dataset.down,10); if (idx<routePoints.length-1){ const tmp = routePoints[idx+1]; routePoints[idx+1]=routePoints[idx]; routePoints[idx]=tmp; updateHidden(); renderQueue(); renderPlanner(); } }); });
            wrap.querySelectorAll('[data-remove]').forEach(function(btn){ btn.addEventListener('click', function(){ const idx = parseInt(btn.dataset.remove,10); routePoints.splice(idx,1); updateHidden(); renderQueue(); renderPlanner(); }); });
          }

          function updateAddressDatalist(){
            const projectId = document.getElementById('rp-project')?.value || '0';
            const options = campaignAddressOptions[projectId] || [];
            const list = document.getElementById('rp-address-options');
            if (!list) return;
            list.innerHTML = options.map(function(opt){
              const value = opt.label || opt.address || '';
              const label = opt.address || value;
              return '<option value="' + escapeHtml(value) + '" label="' + escapeHtml(label) + '"></option>';
            }).join('');
          }


          async function updateOwnerOptions(){
            const ownerSel = document.getElementById('rp-owner');
            if (!ownerSel) return;
            const current = ownerSel.value || '';
            const clientId = document.getElementById('rp-client')?.value || '0';
            const projectId = document.getElementById('rp-project')?.value || '0';
            const url = new URL(ownerAjaxUrl, window.location.origin);
            url.searchParams.set('action', 'routespro_users');
            if (clientId && clientId !== '0') url.searchParams.set('client_id', clientId);
            if (projectId && projectId !== '0') url.searchParams.set('project_id', projectId);
            try {
              const res = await fetch(url.toString(), {credentials:'same-origin'});
              const rows = await res.json();
              const options = Array.isArray(rows) ? rows : [];
              ownerSel.innerHTML = '<option value="">--</option>' + options.map(function(u){
                const id = parseInt(u.ID || 0, 10);
                const label = u.label || u.displayName || u.username || ('User ' + id);
                return '<option value="' + id + '">' + escapeHtml(label) + '</option>';
              }).join('');
              if (current) ownerSel.value = current;
              if (current && ownerSel.value !== current) {
                const opt = document.createElement('option');
                opt.value = current;
                opt.textContent = 'Owner atual #' + current;
                ownerSel.appendChild(opt);
                ownerSel.value = current;
              }
            } catch(err) {}
          }

          function applyRouteDefaults(){
            const routeId = document.querySelector('input[name="id"]')?.value || '0';
            if (routeId !== '0') return;
            const clientId = document.getElementById('rp-client')?.value || '0';
            const projectId = document.getElementById('rp-project')?.value || '0';
            const ownerId = document.getElementById('rp-owner')?.value || '0';
            const defaults = routeDefaults[clientId + '|' + projectId + '|' + ownerId]
              || routeDefaults[clientId + '|' + projectId + '|0']
              || routeDefaults[clientId + '|0|' + ownerId]
              || routeDefaults[clientId + '|0|0'];
            if (!defaults) return;
            if (defaults.start_point) {
              if (!document.getElementById('rp-start-address').value) document.getElementById('rp-start-address').value = defaults.start_point.address || '';
              if (!document.getElementById('rp-start-lat').value) document.getElementById('rp-start-lat').value = defaults.start_point.lat || '';
              if (!document.getElementById('rp-start-lng').value) document.getElementById('rp-start-lng').value = defaults.start_point.lng || '';
            }
            if (defaults.end_point) {
              if (!document.getElementById('rp-end-address').value) document.getElementById('rp-end-address').value = defaults.end_point.address || '';
              if (!document.getElementById('rp-end-lat').value) document.getElementById('rp-end-lat').value = defaults.end_point.lat || '';
              if (!document.getElementById('rp-end-lng').value) document.getElementById('rp-end-lng').value = defaults.end_point.lng || '';
            }
          }

          let routePlacesService = null;
          function getRoutePlaceDetails(placeId, cb){
            if (!(window.google && google.maps && google.maps.places) || !placeId) { cb(null); return; }
            if (!routePlacesService) routePlacesService = new google.maps.places.PlacesService(document.createElement('div'));
            routePlacesService.getDetails({placeId: placeId, fields:['name','formatted_address','geometry','address_components','place_id','formatted_phone_number','international_phone_number']}, function(place, status){
              cb(status === google.maps.places.PlacesServiceStatus.OK ? place : null);
            });
          }

          function loadGoogle(cb){
            if (mapsProvider !== 'google' || !mapsKey) { cb(false); return; }
            if (window.google && google.maps) { cb(true); return; }
            const existing = document.querySelector('script[data-routespro-google="1"]');
            if (existing) { existing.addEventListener('load', function(){ cb(true); }, {once:true}); return; }
            const s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(mapsKey) + '&libraries=places';
            s.async = true; s.defer = true; s.dataset.routesproGoogle = '1';
            s.onload = function(){ cb(true); };
            s.onerror = function(){ cb(false); };
            document.head.appendChild(s);
          }

          function drawDiscoveryMarkers(existingItems, googleItems){
            if (!(window.google && google.maps)) return;
            if (!discoveryMap) discoveryMap = new google.maps.Map(document.getElementById('rp-discovery-map'), {center:{lat:39.5,lng:-8.0}, zoom:6});
            discoveryMarkers.forEach(function(m){ m.setMap(null); });
            discoveryMarkers = [];
            const bounds = new google.maps.LatLngBounds();
            const combined = [];
            (existingItems || []).forEach(function(item){ combined.push({item:item, source:'existing'}); });
            (googleItems || []).forEach(function(item){ combined.push({item:item, source:'google'}); });
            combined.forEach(function(entry){
              const item = entry.item || {};
              if (!Number.isFinite(parseFloat(item.lat)) || !Number.isFinite(parseFloat(item.lng))) return;
              const icon = entry.source === 'google' ? 'http://maps.google.com/mapfiles/ms/icons/red-dot.png' : 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png';
              const marker = new google.maps.Marker({
                map:discoveryMap,
                position:{lat:parseFloat(item.lat), lng:parseFloat(item.lng)},
                title:item.name || item.address || 'PDV',
                icon: icon
              });
              marker.addListener('click', function(){
                if (entry.source === 'google') {
                  populateInlineFromPoint(item);
                  document.getElementById('rp-new-name').focus();
                } else {
                  populateInlineFromPoint(item);
                  addPointToRoute(item);
                }
              });
              discoveryMarkers.push(marker);
              bounds.extend(marker.getPosition());
            });
            if (discoveryMarkers.length) discoveryMap.fitBounds(bounds);
          }

          async function renderPlanner(){
            const kmEl = document.getElementById('rp-summary-km');
            const timeEl = document.getElementById('rp-summary-time');
            const travelTimeEl = document.getElementById('rp-summary-travel-time');
            const visitTimeEl = document.getElementById('rp-summary-visit-time');
            const legsEl = document.getElementById('rp-route-legs');
            kmEl.textContent = '--'; timeEl.textContent = '--'; if (travelTimeEl) travelTimeEl.textContent = '--'; if (visitTimeEl) visitTimeEl.textContent = formatMinutes(routePoints.reduce(function(sum, point){ return sum + (parseInt(point.visit_time_min || 0, 10) || 0); }, 0));
            if (!(window.google && google.maps) || routePoints.length < 2) {
              if (routePoints.length < 2) legsEl.innerHTML = '<em>Adiciona pelo menos 2 PDVs para calcular o percurso.</em>';
              return;
            }
            const valid = routePoints.filter(function(p){ return Number.isFinite(toNum(p.lat)) && Number.isFinite(toNum(p.lng)); });
            if (valid.length < 2) { legsEl.innerHTML = '<em>Faltam coordenadas em alguns PDVs. Usa resultados existentes, Google ou preenche lat/lng no novo PDV.</em>'; return; }
            if (!routeMap) routeMap = new google.maps.Map(document.getElementById('rp-route-map'), {center:{lat:39.5,lng:-8.0}, zoom:6});
            if (!routeDirections) routeDirections = new google.maps.DirectionsRenderer({map: routeMap, suppressMarkers: false});
            const service = new google.maps.DirectionsService();
            const startLat = toNum(document.getElementById('rp-start-lat')?.value);
            const startLng = toNum(document.getElementById('rp-start-lng')?.value);
            const endLat = toNum(document.getElementById('rp-end-lat')?.value);
            const endLng = toNum(document.getElementById('rp-end-lng')?.value);
            const hasFixedStart = Number.isFinite(startLat) && Number.isFinite(startLng);
            const hasFixedEnd = Number.isFinite(endLat) && Number.isFinite(endLng);
            const origin = hasFixedStart ? {lat:startLat, lng:startLng} : {lat: toNum(valid[0].lat), lng: toNum(valid[0].lng)};
            const destination = hasFixedEnd ? {lat:endLat, lng:endLng} : {lat: toNum(valid[valid.length-1].lat), lng: toNum(valid[valid.length-1].lng)};
            const startCut = hasFixedStart ? 0 : 1;
            const endCut = hasFixedEnd ? valid.length : -1;
            const waypoints = valid.slice(startCut, endCut).map(function(p){ return {location:{lat:toNum(p.lat), lng:toNum(p.lng)}, stopover:true}; });
            service.route({origin:origin, destination:destination, waypoints:waypoints, optimizeWaypoints:false, travelMode:google.maps.TravelMode.DRIVING}, function(result, status){
              if (status !== 'OK') { legsEl.innerHTML = '<em>Não foi possível calcular a rota neste momento.</em>'; return; }
              routeDirections.setDirections(result);
              let meters = 0, seconds = 0;
              const legs = result.routes[0].legs || [];
              legsEl.innerHTML = legs.map(function(leg, idx){
                meters += leg.distance.value || 0; seconds += leg.duration.value || 0;
                return '<div class="rp-result"><h4>'+escapeHtml((valid[idx]?.name || valid[idx]?.address || ('Ponto '+(idx+1)))+' → '+(valid[idx+1]?.name || valid[idx+1]?.address || ('Ponto '+(idx+2))))+'</h4><div class="rp-muted">'+escapeHtml(leg.start_address || '')+'</div><div style="font-size:12px;color:#475569;margin-top:6px">'+escapeHtml((leg.distance?.text || '--') + ' • ' + (leg.duration?.text || '--'))+'</div></div>';
              }).join('');
              kmEl.textContent = (meters/1000).toFixed(1) + ' km';
              const mins = Math.round(seconds/60);
              const visitMins = routePoints.reduce(function(sum, point){ return sum + (parseInt(point.visit_time_min || 0, 10) || 0); }, 0);
              if (travelTimeEl) travelTimeEl.textContent = formatMinutes(mins);
              if (visitTimeEl) visitTimeEl.textContent = formatMinutes(visitMins);
              timeEl.textContent = formatMinutes(mins + visitMins);
            });
          }

          ['rp-client','rp-project','rp-owner'].forEach(function(id){ const el = document.getElementById(id); if (el) el.addEventListener('change', function(){ if (id !== 'rp-owner') updateOwnerOptions(); updateAddressDatalist(); applyRouteDefaults(); renderPlanner(); }); });
          updateOwnerOptions();

          loadGoogle(function(loaded){
            if (!loaded) {
              document.getElementById('rp-discovery-map-wrap').innerHTML = '<div class="rp-muted">Ativa Google Maps em Settings para ver o mapa de descoberta e o cálculo automático de distâncias.</div>';
              document.getElementById('rp-route-map').innerHTML = '<div class="rp-muted" style="padding:14px">Ativa Google Maps em Settings para calcular kms e tempo automaticamente.</div>';
            } else {
              const addressInput = document.getElementById('rp-new-address');
              if (addressInput) {
                const ac = new google.maps.places.Autocomplete(addressInput, {fields:['formatted_address','geometry','name','address_components','place_id']});
                ac.addListener('place_changed', function(){
                  const plc = ac.getPlace();
                  if (!plc) return;
                  const parsed = parseAddressComponents(plc.address_components || []);
                  document.getElementById('rp-new-name').value = document.getElementById('rp-new-name').value || plc.name || '';
                  document.getElementById('rp-new-address').value = plc.formatted_address || addressInput.value || '';
                  document.getElementById('rp-new-district').value = parsed.district || document.getElementById('rp-new-district').value || '';
                  document.getElementById('rp-new-county').value = parsed.county || document.getElementById('rp-new-county').value || '';
                  document.getElementById('rp-new-city').value = parsed.city || document.getElementById('rp-new-city').value || '';
                  if (plc.geometry) {
                    document.getElementById('rp-new-lat').value = String(plc.geometry.location.lat());
                    document.getElementById('rp-new-lng').value = String(plc.geometry.location.lng());
                  }
                  getRoutePlaceDetails(plc.place_id || '', function(details){
                    if (!details) return;
                    if (!document.getElementById('rp-new-phone').value) document.getElementById('rp-new-phone').value = details.international_phone_number || details.formatted_phone_number || '';
                    const components = details.address_components || plc.address_components || [];
                    const parsedDetails = parseAddressComponents(components);
                    if (!document.getElementById('rp-new-district').value) document.getElementById('rp-new-district').value = parsedDetails.district || '';
                    if (!document.getElementById('rp-new-county').value) document.getElementById('rp-new-county').value = parsedDetails.county || '';
                    if (!document.getElementById('rp-new-city').value) document.getElementById('rp-new-city').value = parsedDetails.city || '';
                  });
                });
              }
              [['rp-start-address','rp-start-lat','rp-start-lng'],['rp-end-address','rp-end-lat','rp-end-lng']].forEach(function(cfg){
                const input = document.getElementById(cfg[0]);
                if (!input) return;
                const ac = new google.maps.places.Autocomplete(input, {fields:['formatted_address','geometry','name']});
                ac.addListener('place_changed', function(){
                  const plc = ac.getPlace();
                  if (!plc) return;
                  input.value = plc.formatted_address || input.value || '';
                  if (plc.geometry) {
                    document.getElementById(cfg[1]).value = String(plc.geometry.location.lat());
                    document.getElementById(cfg[2]).value = String(plc.geometry.location.lng());
                  }
                  renderPlanner();
                });
              });
            }
            updateAddressDatalist();
            applyRouteDefaults();
            renderQueue();
            renderPlanner();
            runDiscovery();
          });

          document.getElementById('rp-route-form').addEventListener('submit', function(ev){
            if (!routePoints.length) {
              ev.preventDefault();
              alert('Adiciona pelo menos um PDV à rota antes de guardar.');
              return;
            }
            updateHidden();
          });
        });
        </script>
        <?php
        echo '</div>';
    }
}
