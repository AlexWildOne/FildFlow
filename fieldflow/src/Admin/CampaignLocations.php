<?php
namespace RoutesPro\Admin;

use RoutesPro\Support\Permissions;
use RoutesPro\Services\LocationDeduplicator;

if (!defined('ABSPATH')) exit;

class CampaignLocations {
    public static function render() {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $projects_tbl = $px . 'projects';
        $clients_tbl = $px . 'clients';
        $locations_tbl = $px . 'locations';
        $links_tbl = $px . 'campaign_locations';
        $cats_tbl = $px . 'categories';

        $project_id = absint($_REQUEST['project_id'] ?? 0);
        $q = sanitize_text_field($_REQUEST['q'] ?? '');
        $category_id = absint($_REQUEST['category_id'] ?? 0);
        $week_start = sanitize_text_field($_REQUEST['week_start'] ?? date('Y-m-d'));
        $selected_project = $project_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A) : null;
        $selected_client_id = (int)($selected_project['client_id'] ?? 0);
        $users = Permissions::get_associated_users($selected_client_id, $project_id, ['ID','display_name','user_login']);
        $settings = get_option('routespro_settings', []);
        $gmKey = trim((string)($settings['google_maps_key'] ?? ''));
        $mapsProvider = trim((string)($settings['maps_provider'] ?? 'leaflet'));
        $selected_owner_user_id = absint($_REQUEST['owner_user_id'] ?? ($_POST['owner_user_id'] ?? 0));
        $linked_page = max(1, absint($_REQUEST['linked_page'] ?? 1));
        $linked_per_page = absint($_REQUEST['linked_per_page'] ?? 20);
        if (!in_array($linked_per_page, [10,20,50,100], true)) $linked_per_page = 20;
        $linked_q = sanitize_text_field($_REQUEST['linked_q'] ?? ($_POST['linked_q'] ?? ''));
        $linked_category_id = absint($_REQUEST['linked_category_id'] ?? ($_POST['linked_category_id'] ?? 0));
        $linked_status = sanitize_text_field($_REQUEST['linked_status'] ?? ($_POST['linked_status'] ?? ''));
        if (!in_array($linked_status, ['', 'active', 'paused'], true)) $linked_status = '';
        $linked_active = sanitize_text_field($_REQUEST['linked_active'] ?? ($_POST['linked_active'] ?? ''));
        if (!in_array($linked_active, ['', '1', '0'], true)) $linked_active = '';
        $holiday_country = strtolower(sanitize_text_field($_REQUEST['holiday_country'] ?? ($_POST['holiday_country'] ?? 'pt')));
        if (!in_array($holiday_country, ['pt','es'], true)) $holiday_country = 'pt';
        $simulation_options = self::normalize_plan_options([
            'max_stops_per_day' => absint($_REQUEST['simulation_max_stops'] ?? ($_POST['simulation_max_stops'] ?? 12)),
            'work_minutes' => absint($_REQUEST['simulation_work_minutes'] ?? ($_POST['simulation_work_minutes'] ?? 0)),
            'simulation_work_hours' => wp_unslash($_REQUEST['simulation_work_hours'] ?? ($_POST['simulation_work_hours'] ?? '8')),
            'lunch_minutes' => absint($_REQUEST['simulation_lunch_minutes'] ?? ($_POST['simulation_lunch_minutes'] ?? 60)),
            'allow_overtime' => !empty($_REQUEST['simulation_allow_overtime']) || !empty($_POST['simulation_allow_overtime']),
            'overtime_extra_minutes' => absint($_REQUEST['simulation_overtime_extra_minutes'] ?? ($_POST['simulation_overtime_extra_minutes'] ?? 0)),
            'lock_start_point' => !empty($_REQUEST['simulation_lock_start_point']) || !empty($_POST['simulation_lock_start_point']),
            'lock_end_point' => !empty($_REQUEST['simulation_lock_end_point']) || !empty($_POST['simulation_lock_end_point']),
        ]);

        $routeDefaultsAll = get_option('routespro_route_defaults', []);
        if (!is_array($routeDefaultsAll)) $routeDefaultsAll = [];
        $routeDefaultKey = $selected_client_id . '|' . $project_id . '|' . $selected_owner_user_id;
        $routeDefaults = $routeDefaultsAll[$routeDefaultKey] ?? $routeDefaultsAll[$selected_client_id . '|' . $project_id . '|0'] ?? [];
        $simulation_start = [
            'address' => sanitize_text_field($_REQUEST['simulation_start_address'] ?? ($_POST['simulation_start_address'] ?? ($routeDefaults['start_point']['address'] ?? ''))),
            'lat' => is_numeric($_REQUEST['simulation_start_lat'] ?? ($_POST['simulation_start_lat'] ?? ($routeDefaults['start_point']['lat'] ?? ''))) ? (float)($_REQUEST['simulation_start_lat'] ?? ($_POST['simulation_start_lat'] ?? ($routeDefaults['start_point']['lat'] ?? ''))) : null,
            'lng' => is_numeric($_REQUEST['simulation_start_lng'] ?? ($_POST['simulation_start_lng'] ?? ($routeDefaults['start_point']['lng'] ?? ''))) ? (float)($_REQUEST['simulation_start_lng'] ?? ($_POST['simulation_start_lng'] ?? ($routeDefaults['start_point']['lng'] ?? ''))) : null,
        ];
        $simulation_end = [
            'address' => sanitize_text_field($_REQUEST['simulation_end_address'] ?? ($_POST['simulation_end_address'] ?? ($routeDefaults['end_point']['address'] ?? ''))),
            'lat' => is_numeric($_REQUEST['simulation_end_lat'] ?? ($_POST['simulation_end_lat'] ?? ($routeDefaults['end_point']['lat'] ?? ''))) ? (float)($_REQUEST['simulation_end_lat'] ?? ($_POST['simulation_end_lat'] ?? ($routeDefaults['end_point']['lat'] ?? ''))) : null,
            'lng' => is_numeric($_REQUEST['simulation_end_lng'] ?? ($_POST['simulation_end_lng'] ?? ($routeDefaults['end_point']['lng'] ?? ''))) ? (float)($_REQUEST['simulation_end_lng'] ?? ($_POST['simulation_end_lng'] ?? ($routeDefaults['end_point']['lng'] ?? ''))) : null,
        ];
        $daily_overtime_dates = array_values(array_filter(array_map('sanitize_text_field', (array)($_REQUEST['simulation_overtime_dates'] ?? ($_POST['simulation_overtime_dates'] ?? [])))));
        $daily_overtime_hours_raw = (array)($_REQUEST['simulation_overtime_hours'] ?? ($_POST['simulation_overtime_hours'] ?? []));
        $daily_overtime_minutes = [];
        foreach ($daily_overtime_hours_raw as $date => $hoursRaw) {
            $date = sanitize_text_field((string)$date);
            if (!$date) continue;
            $mins = (int) round(max(0, min(8, (float)$hoursRaw)) * 60);
            if ($mins > 0) $daily_overtime_minutes[$date] = $mins;
        }
        foreach ($daily_overtime_dates as $date) {
            if (!isset($daily_overtime_minutes[$date])) $daily_overtime_minutes[$date] = (int)($simulation_options['overtime_extra_minutes'] ?? 60);
        }
        $simulation_options['daily_overtime_dates'] = $daily_overtime_dates;
        $simulation_options['daily_overtime_minutes'] = $daily_overtime_minutes;
        $simulation_options['start_point'] = $simulation_start;
        $simulation_options['end_point'] = $simulation_end;

        if (!empty($_POST['routespro_campaign_locations_nonce']) && wp_verify_nonce($_POST['routespro_campaign_locations_nonce'], 'routespro_campaign_locations')) {
            $action = sanitize_text_field($_POST['campaign_action'] ?? '');
            if ($action === 'add' && $project_id) {
                $ids = array_map('absint', (array)($_POST['location_ids'] ?? []));
                foreach ($ids as $location_id) {
                    if (!$location_id) continue;
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$links_tbl} (project_id, location_id, status, is_active, visit_frequency, frequency_count, visit_duration_min) VALUES (%d,%d,'active',1,'weekly',1,45)", $project_id, $location_id));
                }
                echo '<div class="updated notice"><p>PDVs associados à campanha.</p></div>';
            }
            if ($action === 'remove' && $project_id) {
                $link_id = absint($_POST['link_id'] ?? 0);
                if ($link_id) {
                    $wpdb->delete($links_tbl, ['id' => $link_id]);
                    echo '<div class="updated notice"><p>PDV removido da campanha.</p></div>';
                }
            }
            if ($action === 'update_plan' && $project_id) {
                $link_id = absint($_POST['link_id'] ?? 0);
                if ($link_id) {
                    $updated = self::update_campaign_link_plan($link_id, $_POST);
                    if ($updated) {
                        echo '<div class="updated notice"><p>Planeamento do PDV atualizado.</p></div>';
                    }
                }
            }
            if ($action === 'bulk_update_plan' && $project_id) {
                $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : [];
                $updated = 0;
                foreach ($rows as $link_id => $row) {
                    $link_id = absint($link_id);
                    if (!$link_id || !is_array($row)) continue;
                    $updated += self::update_campaign_link_plan($link_id, $row) ? 1 : 0;
                }
                echo '<div class="updated notice"><p>' . intval($updated) . ' PDVs atualizados de uma só vez.</p></div>';
            }
            if (in_array($action, ['export_linked_filtered','export_linked_all'], true) && $project_id) {
                $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A);
                if ($project) {
                    $exportFilters = [
                        'q' => sanitize_text_field($_POST['linked_q'] ?? ''),
                        'category_id' => absint($_POST['linked_category_id'] ?? 0),
                        'status' => sanitize_text_field($_POST['linked_status'] ?? ''),
                        'active' => sanitize_text_field($_POST['linked_active'] ?? ''),
                        'owner_user_id' => absint($_POST['owner_user_id'] ?? 0),
                    ];
                    if ($action === 'export_linked_all') {
                        $exportFilters = ['q' => '', 'category_id' => 0, 'status' => '', 'active' => '', 'owner_user_id' => 0];
                    }
                    self::stream_linked_locations_csv($project, self::get_campaign_linked_rows($project_id, $exportFilters), $exportFilters);
                }
            }
            if (in_array($action, ['accept_week','export_plan'], true) && $project_id) {
                $owner_user_id = absint($_POST['owner_user_id'] ?? 0);
                $week_start = sanitize_text_field($_POST['week_start'] ?? date('Y-m-d'));
                $holiday_country = strtolower(sanitize_text_field($_POST['holiday_country'] ?? 'pt'));
                if (!in_array($holiday_country, ['pt','es'], true)) $holiday_country = 'pt';
                $simulation_options = self::normalize_plan_options([
                    'max_stops_per_day' => absint($_POST['simulation_max_stops'] ?? 12),
                    'work_minutes' => absint($_POST['simulation_work_minutes'] ?? 0),
                    'simulation_work_hours' => wp_unslash($_POST['simulation_work_hours'] ?? '8'),
                    'lunch_minutes' => absint($_POST['simulation_lunch_minutes'] ?? 60),
                    'allow_overtime' => !empty($_POST['simulation_allow_overtime']),
                    'overtime_extra_minutes' => absint($_POST['simulation_overtime_extra_minutes'] ?? 0),
                    'lock_start_point' => !empty($_POST['simulation_lock_start_point']),
                    'lock_end_point' => !empty($_POST['simulation_lock_end_point']),
                    'daily_overtime_dates' => (array)($_POST['simulation_overtime_dates'] ?? []),
                    'daily_overtime_minutes' => (array)($_POST['simulation_overtime_minutes'] ?? []),
                    'start_point' => [
                        'address' => sanitize_text_field($_POST['simulation_start_address'] ?? ''),
                        'lat' => is_numeric($_POST['simulation_start_lat'] ?? null) ? (float)$_POST['simulation_start_lat'] : null,
                        'lng' => is_numeric($_POST['simulation_start_lng'] ?? null) ? (float)$_POST['simulation_start_lng'] : null,
                    ],
                    'end_point' => [
                        'address' => sanitize_text_field($_POST['simulation_end_address'] ?? ''),
                        'lat' => is_numeric($_POST['simulation_end_lat'] ?? null) ? (float)$_POST['simulation_end_lat'] : null,
                        'lng' => is_numeric($_POST['simulation_end_lng'] ?? null) ? (float)$_POST['simulation_end_lng'] : null,
                    ],
                ]);
                $plan_scope = sanitize_text_field($_POST['plan_scope'] ?? 'weekly');
                if (!in_array($plan_scope, ['weekly','monthly'], true)) $plan_scope = 'weekly';
                $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A);
                if ($project) {
                    $linkedForPlan = self::get_campaign_linked_rows($project_id, ['owner_user_id' => $owner_user_id]);
                    $plan = self::build_period_plan($linkedForPlan, $plan_scope, $week_start, $holiday_country, $simulation_options);
                    if ($action === 'export_plan') {
                        self::stream_plan_csv($project, $plan, $plan_scope, $week_start, $holiday_country, $simulation_options);
                    }
                    $created = self::create_routes_from_plan((int)($project['client_id'] ?? 0), $project_id, $owner_user_id, $week_start, $plan);
                    $label = $plan_scope === 'monthly' ? 'Plano mensal aceite.' : 'Semana aceite.';
                    echo '<div class="updated notice"><p>' . esc_html($label) . ' ' . intval($created) . ' rotas criadas automaticamente.</p></div>';
                }
            }
        }

        $projects = $wpdb->get_results("SELECT p.id,p.name,c.name AS client_name FROM {$projects_tbl} p LEFT JOIN {$clients_tbl} c ON c.id=p.client_id ORDER BY c.name ASC, p.name ASC", ARRAY_A) ?: [];
        $categories = $wpdb->get_results("SELECT id,name FROM {$cats_tbl} WHERE parent_id IS NULL AND is_active=1 ORDER BY name ASC", ARRAY_A) ?: [];

        $where = ["(l.location_type IN ('pdv','') OR l.location_type IS NULL)", "l.is_active=1"];
        $args = [];
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.city LIKE %s OR l.phone LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        if ($category_id) {
            $where[] = '(l.category_id=%d OR cat.parent_id=%d)';
            array_push($args, $category_id, $category_id);
        }
        if ($project_id) {
            $where[] = 'l.id NOT IN (SELECT location_id FROM ' . $links_tbl . ' WHERE project_id=%d)';
            $args[] = $project_id;
        }
        $sql = "SELECT l.*, c.name AS category_name, sc.name AS subcategory_name FROM {$locations_tbl} l LEFT JOIN {$cats_tbl} c ON c.id=l.category_id LEFT JOIN {$cats_tbl} sc ON sc.id=l.subcategory_id LEFT JOIN {$cats_tbl} cat ON cat.id=l.subcategory_id WHERE " . implode(' AND ', $where) . " ORDER BY l.updated_at DESC, l.id DESC LIMIT 200";
        $available = $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
        $available = LocationDeduplicator::dedupe_rows($available);

        $linkedAll = $project_id ? self::get_campaign_linked_rows($project_id) : [];
        $linkedFiltered = $project_id ? self::get_campaign_linked_rows($project_id, [
            'q' => $linked_q,
            'category_id' => $linked_category_id,
            'status' => $linked_status,
            'active' => $linked_active,
            'owner_user_id' => $selected_owner_user_id,
        ]) : [];
        $linkedCount = count($linkedFiltered);
        $linkedOffset = ($linked_page - 1) * $linked_per_page;
        $linked = array_slice($linkedFiltered, $linkedOffset, $linked_per_page);
        $days5 = $linkedCount ? ceil($linkedCount / 5) : 0;
        $days6 = $linkedCount ? ceil($linkedCount / 6) : 0;
        $visitMinutes = array_reduce($linkedFiltered, function($sum, $row){ return $sum + max(0, (int)($row['visit_duration_min'] ?? 45)); }, 0);
        $plan_scope = sanitize_text_field($_REQUEST['plan_scope'] ?? 'weekly');
        if (!in_array($plan_scope, ['weekly','monthly'], true)) $plan_scope = 'weekly';
        $linkedForSuggestion = self::filter_linked_by_owner($linkedAll, $selected_owner_user_id);
        $suggested = $project_id ? self::build_period_plan($linkedForSuggestion, $plan_scope, $week_start, $holiday_country, $simulation_options) : [];

        echo '<div class="wrap">';
        Branding::render_header('Campanhas PDVs', 'Liga a BD comercial global às campanhas sem duplicar lojas. Define periodicidade por campanha e gera uma semana sugerida de rotas.');
        echo '<div class="routespro-card" style="margin-top:18px">';
        echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">';
        echo '<input type="hidden" name="page" value="routespro-campaign-locations">';
        echo '<label>Campanha<br><select name="project_id" style="min-width:340px"><option value="">Selecionar campanha</option>';
        foreach ($projects as $p) {
            echo '<option value="'.intval($p['id']).'" '.selected($project_id, intval($p['id']), false).'>'.esc_html(($p['client_name'] ? $p['client_name'].' · ' : '').$p['name']).'</option>';
        }
        echo '</select></label>';
        echo '<label>Categoria<br><select name="category_id"><option value="">Todas</option>';
        foreach ($categories as $c) echo '<option value="'.intval($c['id']).'" '.selected($category_id, intval($c['id']), false).'>'.esc_html($c['name']).'</option>';
        echo '</select></label>';
        echo '<label>Pesquisar<br><input type="search" name="q" value="'.esc_attr($q).'" placeholder="Nome, morada, cidade"></label>';
        echo '<label>Data base<br><input type="date" name="week_start" value="'.esc_attr($week_start).'"></label>';
        echo '<label>Modo de sugestão<br><select name="plan_scope"><option value="weekly" '.selected($plan_scope, 'weekly', false).'>Semanal</option><option value="monthly" '.selected($plan_scope, 'monthly', false).'>Mensal</option></select></label>';
        echo '<label>Feriados<br><select name="holiday_country"><option value="pt" '.selected($holiday_country, 'pt', false).'>Portugal</option><option value="es" '.selected($holiday_country, 'es', false).'>Espanha</option></select></label>';
        echo '<label>Owner da sugestão<br><select name="owner_user_id"><option value="0">Todos</option>';
        foreach ($users as $u) echo '<option value="'.intval($u->ID).'" '.selected($selected_owner_user_id, intval($u->ID), false).'>'.esc_html($u->display_name.' ['.$u->user_login.']').'</option>';
        echo '</select></label>';
        echo '<label>Máx. visitas/dia<br><input type="number" min="1" max="20" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'" style="width:90px"></label>';
        echo '<label>Horas úteis<br><input type="number" min="1" max="12" step="0.5" name="simulation_work_hours" value="'.esc_attr(number_format($simulation_options['work_minutes'] / 60, 1, '.', '')).'" style="width:90px"></label>';
        echo '<label>Almoço<br><input type="number" min="0" max="180" step="15" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'" style="width:90px"> min</label>';
        echo '<label style="display:flex;align-items:center;gap:6px;padding-bottom:6px"><input type="checkbox" name="simulation_allow_overtime" value="1" '.checked(!empty($simulation_options['allow_overtime']), true, false).'> Permitir fora do horário, geral</label>';
        echo '<label>Ponto de partida<br><input type="text" id="routespro-simulation-start-address" class="routespro-simulation-address" data-lat="#routespro-simulation-start-lat" data-lng="#routespro-simulation-start-lng" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'" placeholder="Morada inicial" style="min-width:240px"><input type="hidden" id="routespro-simulation-start-lat" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'"><input type="hidden" id="routespro-simulation-start-lng" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'"><span style="display:block;font-size:11px;color:#64748b;margin-top:4px">Autocomplete e preenchimento automático de coordenadas.</span></label>';
        echo '<label>Ponto de chegada<br><input type="text" id="routespro-simulation-end-address" class="routespro-simulation-address" data-lat="#routespro-simulation-end-lat" data-lng="#routespro-simulation-end-lng" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'" placeholder="Morada final" style="min-width:240px"><input type="hidden" id="routespro-simulation-end-lat" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'"><input type="hidden" id="routespro-simulation-end-lng" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'"><span style="display:block;font-size:11px;color:#64748b;margin-top:4px">Autocomplete e preenchimento automático de coordenadas.</span></label>';
        echo '<button class="button button-primary">Atualizar</button>';
        echo '</form>';
        if ($project_id) {
            echo '<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px">';
            foreach ([['PDVs na campanha',$linkedCount],['Dias alvo, 5 lojas/dia',$days5],['Dias stretch, 6 lojas/dia',$days6],['Tempo total em loja', ($visitMinutes ? floor($visitMinutes/60).'h '.($visitMinutes%60).'m' : '0m')]] as $card) {
                echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px"><div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em">'.esc_html($card[0]).'</div><div style="font-size:28px;font-weight:800;margin-top:6px">'.esc_html($card[1]).'</div></div>';
            }
            echo '</div>';
        }
        echo '</div>';

        if ($project_id) {
            echo '<div class="routespro-card" style="margin-top:18px">';
            echo '<h2 style="margin-top:0">Adicionar lojas existentes à campanha</h2>';
            echo '<form method="post">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'">';
            echo '<input type="hidden" name="owner_user_id" value="'.intval($selected_owner_user_id).'">';
            echo '<input type="hidden" name="linked_page" value="'.intval($linked_page).'">';
            echo '<input type="hidden" name="linked_per_page" value="'.intval($linked_per_page).'">';
            echo '<input type="hidden" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'">';
            echo '<input type="hidden" name="simulation_work_minutes" value="'.intval($simulation_options['work_minutes']).'">';
            echo '<input type="hidden" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'">';
            echo '<input type="hidden" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'">';
            if (!empty($simulation_options['lock_start_point'])) echo '<input type="hidden" name="simulation_lock_start_point" value="1">';
            if (!empty($simulation_options['lock_end_point'])) echo '<input type="hidden" name="simulation_lock_end_point" value="1">';
            foreach ((array)($simulation_options['daily_overtime_dates'] ?? []) as $overtimeDate) echo '<input type="hidden" name="simulation_overtime_dates[]" value="'.esc_attr($overtimeDate).'">';
            foreach ((array)($simulation_options['daily_overtime_minutes'] ?? []) as $oDate => $oMin) echo '<input type="hidden" name="simulation_overtime_minutes['.esc_attr((string)$oDate).']" value="'.esc_attr((string)$oMin).'">';
            if (!empty($simulation_options['allow_overtime'])) echo '<input type="hidden" name="simulation_allow_overtime" value="1">';
            echo '<input type="hidden" name="campaign_action" value="add">';
            echo '<table class="widefat striped"><thead><tr><th></th><th>Nome</th><th>Morada</th><th>Cidade</th><th>Categoria</th><th>Telefone</th></tr></thead><tbody>';
            if (!$available) {
                echo '<tr><td colspan="6">Sem PDVs disponíveis com estes filtros.</td></tr>';
            } else {
                foreach ($available as $row) {
                    echo '<tr><td><input type="checkbox" name="location_ids[]" value="'.intval($row['id']).'"></td><td>'.esc_html($row['name']).'</td><td>'.esc_html($row['address']).'</td><td>'.esc_html($row['city']).'</td><td>'.esc_html($row['subcategory_name'] ?: $row['category_name']).'</td><td>'.esc_html($row['phone']).'</td></tr>';
                }
            }
            echo '</tbody></table><p><button class="button button-primary">Associar selecionados</button></p></form></div>';

            echo '<div class="routespro-card" style="margin-top:18px">';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:start;flex-wrap:wrap"><div><h2 style="margin:0">PDVs já ligados à campanha</h2><p style="margin:6px 0 0;color:#64748b">Agora com exportação do resultado filtrado ou da campanha completa.</p></div>';
            echo '<form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">';
            echo '<input type="hidden" name="page" value="routespro-campaign-locations"><input type="hidden" name="project_id" value="'.intval($project_id).'"><input type="hidden" name="category_id" value="'.intval($category_id).'"><input type="hidden" name="q" value="'.esc_attr($q).'"><input type="hidden" name="week_start" value="'.esc_attr($week_start).'"><input type="hidden" name="plan_scope" value="'.esc_attr($plan_scope).'"><input type="hidden" name="holiday_country" value="'.esc_attr($holiday_country).'">';
            echo '<input type="hidden" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'"><input type="hidden" name="simulation_work_minutes" value="'.intval($simulation_options['work_minutes']).'"><input type="hidden" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'"><input type="hidden" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'"><input type="hidden" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'"><input type="hidden" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'"><input type="hidden" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'"><input type="hidden" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'"><input type="hidden" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'"><input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'">'.(!empty($simulation_options['allow_overtime']) ? '<input type="hidden" name="simulation_allow_overtime" value="1">' : '').(!empty($simulation_options['lock_start_point']) ? '<input type="hidden" name="simulation_lock_start_point" value="1">' : '').(!empty($simulation_options['lock_end_point']) ? '<input type="hidden" name="simulation_lock_end_point" value="1">' : '');
            echo '<label>Pesquisar<br><input type="search" name="linked_q" value="'.esc_attr($linked_q).'" placeholder="Nome, morada, cidade"></label>';
            echo '<label>Categoria<br><select name="linked_category_id"><option value="0">Todas</option>';
            foreach ($categories as $c) echo '<option value="'.intval($c['id']).'" '.selected($linked_category_id, intval($c['id']), false).'>'.esc_html($c['name']).'</option>';
            echo '</select></label>';
            echo '<label>Owner<br><select name="owner_user_id"><option value="0">Todos</option>';
            foreach ($users as $u) echo '<option value="'.intval($u->ID).'" '.selected($selected_owner_user_id, intval($u->ID), false).'>'.esc_html($u->display_name.' ['.$u->user_login.']').'</option>';
            echo '</select></label>';
            echo '<label>Estado<br><select name="linked_status"><option value="">Todos</option><option value="active" '.selected($linked_status, 'active', false).'>Ativo</option><option value="paused" '.selected($linked_status, 'paused', false).'>Pausado</option></select></label>';
            echo '<label>Ligação<br><select name="linked_active"><option value="">Todas</option><option value="1" '.selected($linked_active, '1', false).'>Ativas</option><option value="0" '.selected($linked_active, '0', false).'>Inativas</option></select></label>';
            echo '<label>Itens por página<br><select name="linked_per_page" onchange="this.form.submit()">';
            foreach ([10,20,50,100] as $pp) echo '<option value="'.intval($pp).'" '.selected($linked_per_page, $pp, false).'>'.intval($pp).'</option>';
            echo '</select></label><button class="button">Filtrar</button></form></div>';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0 14px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px">';
            echo '<div style="font-size:13px;color:#334155"><strong>'.intval($linkedCount).'</strong> PDVs no resultado atual, de um total de <strong>'.intval(count($linkedAll)).'</strong> na campanha.</div>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
            echo '<form method="post" style="margin:0">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'"><input type="hidden" name="campaign_action" value="export_linked_filtered"><input type="hidden" name="owner_user_id" value="'.intval($selected_owner_user_id).'"><input type="hidden" name="linked_q" value="'.esc_attr($linked_q).'"><input type="hidden" name="linked_category_id" value="'.intval($linked_category_id).'"><input type="hidden" name="linked_status" value="'.esc_attr($linked_status).'"><input type="hidden" name="linked_active" value="'.esc_attr($linked_active).'">';
            echo '<button class="button">Exportar filtrados</button></form>';
            echo '<form method="post" style="margin:0">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'"><input type="hidden" name="campaign_action" value="export_linked_all">';
            echo '<button class="button button-secondary">Exportar todos</button></form>';
            echo '</div></div>';
            echo '<form method="post" id="routespro-bulk-linked-form">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'"><input type="hidden" name="owner_user_id" value="'.intval($selected_owner_user_id).'"><input type="hidden" name="holiday_country" value="'.esc_attr($holiday_country).'"><input type="hidden" name="linked_page" value="'.intval($linked_page).'"><input type="hidden" name="linked_per_page" value="'.intval($linked_per_page).'"><input type="hidden" name="linked_q" value="'.esc_attr($linked_q).'"><input type="hidden" name="linked_category_id" value="'.intval($linked_category_id).'"><input type="hidden" name="linked_status" value="'.esc_attr($linked_status).'"><input type="hidden" name="linked_active" value="'.esc_attr($linked_active).'"><input type="hidden" name="campaign_action" value="bulk_update_plan"><input type="hidden" name="link_id" value="0"><input type="hidden" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'"><input type="hidden" name="simulation_work_minutes" value="'.intval($simulation_options['work_minutes']).'"><input type="hidden" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'"><input type="hidden" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'"><input type="hidden" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'"><input type="hidden" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'"><input type="hidden" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'"><input type="hidden" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'"><input type="hidden" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'"><input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'">'.(!empty($simulation_options['allow_overtime']) ? '<input type="hidden" name="simulation_allow_overtime" value="1">' : '').(!empty($simulation_options['lock_start_point']) ? '<input type="hidden" name="simulation_lock_start_point" value="1">' : '').(!empty($simulation_options['lock_end_point']) ? '<input type="hidden" name="simulation_lock_end_point" value="1">' : '');
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px"><div><strong>Gravação em lote</strong><div style="font-size:12px;color:#64748b;margin-top:4px">Altera várias linhas e grava tudo de uma vez.</div></div><div style="display:flex;gap:8px;align-items:center"><span id="routespro-bulk-dirty" style="display:none;font-size:12px;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:999px;padding:4px 10px">Alterações por guardar</span><button class="button button-primary" type="submit">Guardar tudo</button></div></div>';
            echo '<table class="widefat striped"><thead><tr><th>Nome</th><th>Cidade</th><th>Categoria</th><th>Owner</th><th>Periodicidade</th><th>Repetição</th><th>Visita</th><th>Prioridade</th><th>Ativo</th><th>Estado</th><th></th></tr></thead><tbody>';
            if (!$linked) {
                echo '<tr><td colspan="11">Sem PDVs associados à campanha.</td></tr>';
            } else {
                foreach ($linked as $row) {
                    $linkId = intval($row['link_id']);
                    echo '<tr data-linked-row="'.$linkId.'"><td>'.esc_html($row['name']).'<div style="font-size:11px;color:#64748b">'.esc_html($row['phone']).'</div></td><td>'.esc_html($row['city']).'</td><td>'.esc_html($row['subcategory_name'] ?: $row['category_name']).'</td><td>';
                    echo '<select name="rows['.$linkId.'][assigned_to]" style="min-width:180px" data-bulk-field="1"><option value="0">Sem owner</option>';
                    foreach ($users as $u) echo '<option value="'.intval($u->ID).'" '.selected((int)($row['assigned_to'] ?? 0), intval($u->ID), false).'>'.esc_html($u->display_name.' ['.$u->user_login.']').'</option>';
                    echo '</select></td>';
                    echo '<td><select name="rows['.$linkId.'][visit_frequency]" data-bulk-field="1"><option value="weekly" '.selected(($row['visit_frequency'] ?: 'weekly'),'weekly',false).'>Semanal</option><option value="monthly" '.selected(($row['visit_frequency'] ?: ''),'monthly',false).'>Mensal</option></select></td>';
                    echo '<td><input type="number" min="1" max="7" name="rows['.$linkId.'][frequency_count]" value="'.intval($row['frequency_count'] ?: 1).'" style="width:72px" data-bulk-field="1"></td>';
                    echo '<td><input type="number" min="0" max="360" name="rows['.$linkId.'][visit_duration_min]" value="'.intval($row['visit_duration_min'] ?: 45).'" style="width:82px" data-bulk-field="1"> min</td>';
                    echo '<td><input type="number" min="0" max="999" name="rows['.$linkId.'][priority]" value="'.intval($row['priority'] ?: 0).'" style="width:72px" data-bulk-field="1"></td>';
                    echo '<td><label><input type="checkbox" name="rows['.$linkId.'][is_active]" value="1" '.checked(!empty($row['campaign_active']), true, false).' data-bulk-field="1"> ativo</label></td>';
                    echo '<td><select name="rows['.$linkId.'][status]" data-bulk-field="1"><option value="active" '.selected(($row['campaign_status'] ?: 'active'),'active',false).'>active</option><option value="paused" '.selected(($row['campaign_status'] ?: ''),'paused',false).'>paused</option></select></td>';
                    echo '<td><button class="button-link-delete" type="submit" name="campaign_action" value="remove" onclick="this.form.elements[\'link_id\'].value=\''.$linkId.'\'; return confirm(\'Remover da campanha?\')">Remover</button></td></tr>';
                }
            }
            echo '</tbody></table>';
            echo '<div style="display:flex;justify-content:flex-end;gap:8px;align-items:center;margin-top:12px"><button class="button button-primary" type="submit">Guardar tudo</button></div>';
            echo '</form>';
            echo self::render_linked_pagination($project_id, $category_id, $q, $week_start, $plan_scope, $holiday_country, $selected_owner_user_id, $linked_page, $linked_per_page, $linkedCount, $simulation_options, $linked_q, $linked_category_id, $linked_status, $linked_active);
            echo '<p style="color:#64748b;margin-top:10px">Semanal com repetição 2 ou 3 permite repetir o mesmo local mais do que uma vez na semana. Mensal distribui as visitas ao longo do mês selecionado e pode gerar rotas datadas para esse período.</p>';
            echo '<script>(function(){const form=document.getElementById("routespro-bulk-linked-form"); if(!form) return; const badge=document.getElementById("routespro-bulk-dirty"); let dirty=false; const markDirty=(el)=>{ dirty=true; if(badge) badge.style.display="inline-flex"; const row=el && el.closest("tr[data-linked-row]"); if(row){ row.style.background="#fff7ed"; } }; form.querySelectorAll("[data-bulk-field=\"1\"]").forEach(el=>{ const ev=(el.type==="checkbox"||el.tagName==="SELECT")?"change":"input"; el.addEventListener(ev,()=>markDirty(el)); }); window.addEventListener("beforeunload",function(e){ if(!dirty) return; e.preventDefault(); e.returnValue=""; }); form.addEventListener("submit",function(){ dirty=false; if(badge) badge.style.display="none"; });})();</script>';
            echo '</div>';
            echo '<div class="routespro-card" style="margin-top:18px">';
            echo '<h2 style="margin-top:0">Sugestão automática de rotas</h2>';
            echo '<form method="post" id="routespro-plan-form" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:14px">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'">';
            echo '<input type="hidden" name="linked_page" value="'.intval($linked_page).'">';
            echo '<input type="hidden" name="linked_per_page" value="'.intval($linked_per_page).'">';
            echo '<input type="hidden" name="campaign_action" value="accept_week">';
            echo '<label>Data base<br><input type="date" name="week_start" value="'.esc_attr($week_start).'"></label>';
        echo '<label>Modo de sugestão<br><select name="plan_scope"><option value="weekly" '.selected($plan_scope, 'weekly', false).'>Semanal</option><option value="monthly" '.selected($plan_scope, 'monthly', false).'>Mensal</option></select></label>';
            echo '<label>Feriados<br><select name="holiday_country"><option value="pt" '.selected($holiday_country, 'pt', false).'>Portugal</option><option value="es" '.selected($holiday_country, 'es', false).'>Espanha</option></select></label>';
            echo '<label>Owner das rotas<br><select name="owner_user_id" id="routespro-plan-owner"><option value="0">Sem owner</option>';
            foreach ($users as $u) echo '<option value="'.intval($u->ID).'" '.selected($selected_owner_user_id, intval($u->ID), false).'>'.esc_html($u->display_name.' ['.$u->user_login.']').'</option>';
            echo '</select></label>';
            echo '<label>Máx. visitas/dia<br><input type="number" min="1" max="20" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'" style="width:90px"></label>';
            echo '<label>Horas úteis<br><input type="number" min="1" max="12" step="0.5" name="simulation_work_hours" value="'.esc_attr(number_format($simulation_options['work_minutes'] / 60, 1, '.', '')).'" style="width:90px"></label>';
            echo '<label>Almoço<br><input type="number" min="0" max="180" step="15" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'" style="width:90px"> min</label>';
            echo '<label style="display:flex;align-items:center;gap:6px;padding-bottom:6px"><input type="checkbox" name="simulation_allow_overtime" value="1" '.checked(!empty($simulation_options['allow_overtime']), true, false).'> Permitir fora do horário, geral</label>';
            echo '<label>Horas adicionais, geral<br><input type="number" step="0.5" min="0" max="2.5" name="simulation_overtime_extra_hours" value="'.esc_attr(number_format(((int)($simulation_options['overtime_extra_minutes'] ?? 0))/60, 1, '.', '')).'" style="width:90px"><input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'"></label>';
            echo '<label>Ponto de partida<br><input type="text" id="routespro-plan-start-address" class="routespro-simulation-address" data-lat="#routespro-plan-start-lat" data-lng="#routespro-plan-start-lng" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'" placeholder="Morada inicial" style="min-width:240px"><input type="hidden" id="routespro-plan-start-lat" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'"><input type="hidden" id="routespro-plan-start-lng" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'"><span style="display:block;font-size:12px;color:#475569;margin-top:4px"><label><input type="checkbox" name="simulation_lock_start_point" value="1" '.checked(!empty($simulation_options['lock_start_point']), true, false).'> Bloquear ponto de partida</label></span></label>';
            echo '<label>Ponto de chegada<br><input type="text" id="routespro-plan-end-address" class="routespro-simulation-address" data-lat="#routespro-plan-end-lat" data-lng="#routespro-plan-end-lng" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'" placeholder="Morada final" style="min-width:240px"><input type="hidden" id="routespro-plan-end-lat" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'"><input type="hidden" id="routespro-plan-end-lng" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'"><span style="display:block;font-size:12px;color:#475569;margin-top:4px"><label><input type="checkbox" name="simulation_lock_end_point" value="1" '.checked(!empty($simulation_options['lock_end_point']), true, false).'> Bloquear ponto de chegada</label></span></label>';
            echo '<div style="font-size:12px;color:#64748b;max-width:460px">Por defeito a simulação tenta preencher 8h úteis e 1h de almoço. A periodicidade é prioritária e o motor equilibra distância e carga. As horas extra nunca podem ultrapassar 2h30m por dia. Quando isso não chega, a pré-visualização recomenda reforço de equipa e indica a zona mais lógica para dividir a rota.</div>';
            echo '<div id="routespro-plan-preview">' . self::render_plan_preview_html($suggested, $plan_scope, $selected_owner_user_id, $users, $holiday_country, $simulation_options) . '</div>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px"><button class="button button-primary">Aplicar sugestão e criar rotas</button><button class="button" type="submit" name="campaign_action" value="export_plan">Exportar sugestão</button></div>';
            echo '</form>';
            echo '<script>(function(){const form=document.getElementById("routespro-plan-form"); if(!form) return; const preview=document.getElementById("routespro-plan-preview"); const syncReadonly=()=>{ const lockStart=form.querySelector("input[name=simulation_lock_start_point]"); const lockEnd=form.querySelector("input[name=simulation_lock_end_point]"); const s=form.querySelector("input[name=simulation_start_address]"); const e=form.querySelector("input[name=simulation_end_address]"); if(s&&lockStart) s.readOnly=!!lockStart.checked; if(e&&lockEnd) e.readOnly=!!lockEnd.checked; }; const syncExtra=()=>{ const extra=form.querySelector("input[name=simulation_overtime_extra_hours]"); const hidden=form.querySelector("input[name=simulation_overtime_extra_minutes]"); if(extra&&hidden){ const v=parseFloat(String(extra.value).replace(",","."))||0; hidden.value=String(Math.round(Math.max(0,Math.min(8,v))*60)); } form.querySelectorAll(`input[name^="simulation_overtime_minutes["]`).forEach(function(el){ const v=parseFloat(String(el.value).replace(",","."))||0; el.dataset.minutes=String(Math.round(Math.max(0,Math.min(8,v))*60)); }); }; let t=null; const schedule=()=>{ clearTimeout(t); t=setTimeout(run,180); }; const run=()=>{ syncReadonly(); syncExtra(); if(!preview) return; const fd=new FormData(); fd.append("action","routespro_campaign_plan_preview"); fd.append("project_id",'.intval($project_id).'); fd.append("owner_user_id",form.querySelector("select[name=owner_user_id]")?.value||"0"); fd.append("plan_scope",form.querySelector("select[name=plan_scope]")?.value||"weekly"); fd.append("holiday_country",form.querySelector("select[name=holiday_country]")?.value||"pt"); fd.append("week_start",form.querySelector("input[name=week_start]")?.value||""); fd.append("simulation_max_stops",form.querySelector("input[name=simulation_max_stops]")?.value||"12"); const workHours=parseFloat(form.querySelector("input[name=simulation_work_hours]")?.value||"8")||8; fd.append("simulation_work_minutes",String(Math.round(workHours*60))); fd.append("simulation_lunch_minutes",form.querySelector("input[name=simulation_lunch_minutes]")?.value||"60"); fd.append("simulation_overtime_extra_minutes",form.querySelector("input[name=simulation_overtime_extra_minutes]")?.value||"0"); fd.append("simulation_start_address",form.querySelector("input[name=simulation_start_address]")?.value||""); fd.append("simulation_start_lat",form.querySelector("input[name=simulation_start_lat]")?.value||""); fd.append("simulation_start_lng",form.querySelector("input[name=simulation_start_lng]")?.value||""); fd.append("simulation_end_address",form.querySelector("input[name=simulation_end_address]")?.value||""); fd.append("simulation_end_lat",form.querySelector("input[name=simulation_end_lat]")?.value||""); fd.append("simulation_end_lng",form.querySelector("input[name=simulation_end_lng]")?.value||""); if(form.querySelector("input[name=simulation_allow_overtime]")?.checked){ fd.append("simulation_allow_overtime","1"); } if(form.querySelector("input[name=simulation_lock_start_point]")?.checked){ fd.append("simulation_lock_start_point","1"); } if(form.querySelector("input[name=simulation_lock_end_point]")?.checked){ fd.append("simulation_lock_end_point","1"); } form.querySelectorAll(`input[name="simulation_overtime_dates[]"]:checked`).forEach(el=>fd.append("simulation_overtime_dates[]", el.value)); form.querySelectorAll(`input[name^="simulation_overtime_minutes["]`).forEach(el=>{ const m=el.dataset.minutes||"0"; if(m!=="0") fd.append(el.name, m); }); fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:fd}).then(r=>r.json()).then(resp=>{ if(resp&&resp.success&&resp.data&&resp.data.html!==undefined){ preview.innerHTML=resp.data.html; } }).catch(()=>{}); }; form.addEventListener("change",function(e){ if(!e.target) return; if(e.target.name==="simulation_lock_start_point"||e.target.name==="simulation_lock_end_point"||e.target.name==="simulation_overtime_dates[]") schedule(); else schedule(); }); form.addEventListener("input",function(e){ if(!e.target) return; if(e.target.name==="simulation_overtime_extra_hours"||e.target.name.indexOf("simulation_overtime_minutes[")===0||e.target.name==="simulation_work_hours") schedule(); }); syncReadonly(); syncExtra();})();</script>';
            echo '<script>(function(){const mapsProvider=' . wp_json_encode($mapsProvider) . '; const mapsKey=' . wp_json_encode($gmKey) . '; function loadGoogle(cb){ if(mapsProvider!="google"||!mapsKey){ cb(false); return; } if(window.google&&google.maps&&google.maps.places){ cb(true); return; } const existing=document.querySelector("script[data-routespro-google=\"1\"]"); if(existing){ existing.addEventListener("load",function(){ cb(true); },{once:true}); return; } const s=document.createElement("script"); s.src="https://maps.googleapis.com/maps/api/js?key="+encodeURIComponent(mapsKey)+"&libraries=places"; s.async=true; s.defer=true; s.dataset.routesproGoogle="1"; s.onload=function(){ cb(true); }; s.onerror=function(){ cb(false); }; document.head.appendChild(s); } function refs(input){ return { lat: document.querySelector(input.dataset.lat||""), lng: document.querySelector(input.dataset.lng||"") }; } function trigger(input){ if(input) input.dispatchEvent(new Event("change",{bubbles:true})); } function applyPlace(input, place){ if(!input||!place) return; const r=refs(input); input.value=place.formatted_address||input.value||""; if(place.geometry&&r.lat&&r.lng){ r.lat.value=String(place.geometry.location.lat()); r.lng.value=String(place.geometry.location.lng()); trigger(r.lat); trigger(r.lng); } trigger(input); } function bindOne(input){ if(!input||input.dataset.routesproAcBound==="1"||!(window.google&&google.maps&&google.maps.places&&google.maps.places.Autocomplete)) return; input.dataset.routesproAcBound="1"; const ac=new google.maps.places.Autocomplete(input,{componentRestrictions:{country:["pt","es"]},fields:["formatted_address","geometry","name"]}); ac.addListener("place_changed",function(){ applyPlace(input, ac.getPlace()); }); input.addEventListener("input",function(){ const r=refs(input); if(r.lat) r.lat.value=""; if(r.lng) r.lng.value=""; }); input.addEventListener("blur",function(){ const r=refs(input); if(!input.value.trim()||!r.lat||!r.lng||r.lat.value||r.lng.value||!(window.google&&google.maps&&google.maps.Geocoder)) return; const geocoder=new google.maps.Geocoder(); geocoder.geocode({address:input.value.trim()}, function(results,status){ if(status==="OK"&&results&&results[0]) applyPlace(input, results[0]); }); }); } loadGoogle(function(ok){ if(!ok) return; document.querySelectorAll(".routespro-simulation-address").forEach(bindOne); }); })();</script>';
            echo '</div>';
        }
        echo '</div>';
    }


    private static function update_campaign_link_plan(int $link_id, array $data): bool {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        if ($link_id <= 0) return false;
        $visit_frequency = sanitize_text_field($data['visit_frequency'] ?? 'weekly');
        if (!in_array($visit_frequency, ['weekly','monthly'], true)) $visit_frequency = 'weekly';
        $frequency_count = max(1, min(7, absint($data['frequency_count'] ?? 1)));
        $visit_duration_min = max(0, min(360, absint($data['visit_duration_min'] ?? 45)));
        $priority = max(0, min(999, absint($data['priority'] ?? 0)));
        $status = sanitize_text_field($data['status'] ?? 'active');
        if (!in_array($status, ['active','paused'], true)) $status = 'active';
        $is_active = empty($data['is_active']) ? 0 : 1;
        $assigned_to = absint($data['assigned_to'] ?? 0);
        $updated = $wpdb->update($px . 'campaign_locations', [
            'visit_frequency' => $visit_frequency,
            'frequency_count' => $frequency_count,
            'visit_duration_min' => $visit_duration_min,
            'priority' => $priority,
            'status' => $status,
            'is_active' => $is_active,
            'assigned_to' => $assigned_to ?: null,
        ], ['id' => $link_id], ['%s','%d','%d','%d','%s','%d','%d'], ['%d']);
        return $updated !== false;
    }

    public static function ajax_plan_preview(): void {
        if (!current_user_can('routespro_manage')) wp_send_json_error(['message' => 'forbidden'], 403);
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $project_id = absint($_POST['project_id'] ?? 0);
        $owner_user_id = absint($_POST['owner_user_id'] ?? 0);
        $plan_scope = sanitize_text_field($_POST['plan_scope'] ?? 'weekly');
        if (!in_array($plan_scope, ['weekly','monthly'], true)) $plan_scope = 'weekly';
        $week_start = sanitize_text_field($_POST['week_start'] ?? date('Y-m-d'));
        $holiday_country = strtolower(sanitize_text_field($_POST['holiday_country'] ?? 'pt'));
        if (!in_array($holiday_country, ['pt','es'], true)) $holiday_country = 'pt';
        $simulation_options = self::normalize_plan_options([
            'max_stops_per_day' => absint($_POST['simulation_max_stops'] ?? 12),
            'work_minutes' => absint($_POST['simulation_work_minutes'] ?? 0),
            'simulation_work_hours' => wp_unslash($_POST['simulation_work_hours'] ?? '8'),
            'lunch_minutes' => absint($_POST['simulation_lunch_minutes'] ?? 60),
            'allow_overtime' => !empty($_POST['simulation_allow_overtime']),
            'overtime_extra_minutes' => absint($_POST['simulation_overtime_extra_minutes'] ?? 0),
            'lock_start_point' => !empty($_POST['simulation_lock_start_point']),
            'lock_end_point' => !empty($_POST['simulation_lock_end_point']),
            'daily_overtime_dates' => (array)($_POST['simulation_overtime_dates'] ?? []),
            'daily_overtime_minutes' => (array)($_POST['simulation_overtime_minutes'] ?? []),
            'start_point' => [
                'address' => sanitize_text_field($_POST['simulation_start_address'] ?? ''),
                'lat' => is_numeric($_POST['simulation_start_lat'] ?? null) ? (float)$_POST['simulation_start_lat'] : null,
                'lng' => is_numeric($_POST['simulation_start_lng'] ?? null) ? (float)$_POST['simulation_start_lng'] : null,
            ],
            'end_point' => [
                'address' => sanitize_text_field($_POST['simulation_end_address'] ?? ''),
                'lat' => is_numeric($_POST['simulation_end_lat'] ?? null) ? (float)$_POST['simulation_end_lat'] : null,
                'lng' => is_numeric($_POST['simulation_end_lng'] ?? null) ? (float)$_POST['simulation_end_lng'] : null,
            ],
        ]);
        if (!$project_id) wp_send_json_success(['html' => '<p>Seleciona uma campanha.</p>']);
        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A);
        $users = Permissions::get_associated_users((int)($project['client_id'] ?? 0), $project_id, ['ID','display_name','user_login']);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT cl.id AS link_id, cl.status AS campaign_status, cl.priority, cl.visit_frequency, cl.frequency_count, cl.visit_duration_min, cl.assigned_to, cl.is_active AS campaign_active, l.*, c.name AS category_name, sc.name AS subcategory_name FROM {$px}campaign_locations cl INNER JOIN {$px}locations l ON l.id=cl.location_id LEFT JOIN {$px}categories c ON c.id=l.category_id LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id WHERE cl.project_id=%d ORDER BY cl.priority DESC, l.city ASC, l.name ASC", $project_id), ARRAY_A) ?: [];
        $rows = self::filter_linked_by_owner($rows, $owner_user_id);
        $plan = self::build_period_plan($rows, $plan_scope, $week_start, $holiday_country, $simulation_options);
        wp_send_json_success(['html' => self::render_plan_preview_html($plan, $plan_scope, $owner_user_id, $users, $holiday_country, $simulation_options)]);
    }

    private static function filter_linked_by_owner(array $linked, int $owner_user_id): array {
        if ($owner_user_id <= 0) return $linked;
        return array_values(array_filter($linked, function($row) use ($owner_user_id){
            return (int)($row['assigned_to'] ?? 0) === $owner_user_id;
        }));
    }

    private static function render_plan_preview_html(array $suggested, string $plan_scope, int $owner_user_id = 0, array $users = [], string $holiday_country = 'pt', array $simulation_options = []): string {
        ob_start();
        $ownerLabel = 'Todos os owners';
        if ($owner_user_id > 0) {
            foreach ($users as $u) {
                if ((int)$u->ID === $owner_user_id) { $ownerLabel = $u->display_name . ' [' . $u->user_login . ']'; break; }
            }
        }
        $holidayLabel = strtoupper($holiday_country) === 'ES' ? 'Espanha' : 'Portugal';
        $simulation_options = self::normalize_plan_options($simulation_options ?: (array)($suggested['options'] ?? []));
        $excludedDays = (array)($suggested['excluded_days'] ?? []);
        $excludedDates = array_keys($excludedDays);
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:10px;color:#475569"><div><strong>Filtro owner:</strong> ' . esc_html($ownerLabel) . '</div><div><strong>Feriados:</strong> ' . esc_html($holidayLabel) . '</div><div><strong>Fim de semana:</strong> Sábado e domingo</div><div><strong>Máx. visitas/dia:</strong> ' . intval($simulation_options['max_stops_per_day']) . '</div><div><strong>Horas úteis:</strong> ' . esc_html(self::human_minutes((int)$simulation_options['work_minutes'])) . '</div><div><strong>Almoço:</strong> ' . esc_html(self::human_minutes((int)$simulation_options['lunch_minutes'])) . '</div><div><strong>Fora do horário, geral:</strong> ' . (!empty($simulation_options['allow_overtime']) ? 'Permitido' : 'Não') . '</div><div><strong>Horas adicionais, geral:</strong> ' . esc_html(self::human_minutes((int)($simulation_options['overtime_extra_minutes'] ?? 0))) . '</div><div><strong>Partida:</strong> ' . esc_html((string)($simulation_options['start_point']['address'] ?? 'Sem ponto definido')) . (!empty($simulation_options['lock_start_point']) ? ' · bloqueado' : '') . '</div><div><strong>Chegada:</strong> ' . esc_html((string)($simulation_options['end_point']['address'] ?? 'Sem ponto definido')) . (!empty($simulation_options['lock_end_point']) ? ' · bloqueado' : '') . '</div></div>';
        if ($excludedDates) {
            $parts = [];
            foreach ($excludedDays as $d => $reason) $parts[] = date_i18n('d/m/Y', strtotime((string)$d)) . ' (' . $reason . ')';
            echo '<div style="margin:-2px 0 10px;color:#64748b;font-size:12px">Datas excluídas da sugestão automática: ' . esc_html(implode(', ', $parts)) . '</div>';
        }
        if (!$suggested || empty($suggested['days'])) {
            echo '<p>Sem dados suficientes para gerar a sugestão. Garante PDVs ativos, com coordenadas válidas e, se aplicável, atribuídos ao owner selecionado.</p>';
        } else {
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">';
            foreach ($suggested['days'] as $day) {
                echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px">';
                echo '<div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em">'.esc_html($day['label']).'</div>';
                if (!empty($day['date'])) echo '<div style="margin-top:4px;color:#475569;font-weight:700">'.esc_html(date_i18n('d/m/Y', strtotime((string)$day['date']))).'</div>';
                echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-top:6px;flex-wrap:wrap">';
                echo '<div style="font-size:26px;font-weight:800">'.intval($day['stops']).' lojas</div>';
                if (!empty($day['date'])) {
                    echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
                    echo '<label style="font-size:12px;color:#334155;display:flex;align-items:center;gap:6px"><input type="checkbox" name="simulation_overtime_dates[]" value="'.esc_attr((string)$day['date']).'" '.checked(!empty($day['allow_overtime']), true, false).'> Permitir horas adicionais</label>';
                    echo '<label style="font-size:12px;color:#334155">Horas extra<br><input type="number" step="0.5" min="0" max="8" name="simulation_overtime_minutes['.esc_attr((string)$day['date']).']" value="'.esc_attr(number_format(((int)($day['extra_minutes'] ?? 0))/60, 1, '.', '')).'" style="width:78px"></label>';
                    echo '</div>';
                }
                echo '</div>';
                echo '<div style="margin-top:8px;color:#475569">Viagem: '.esc_html($day['travel_human']).' · Visita: '.esc_html($day['visit_human']).' · Trabalho: '.esc_html($day['work_human'] ?? $day['total_human']).' · Almoço: '.esc_html($day['lunch_human'] ?? '0m') . ' · Total: '.esc_html($day['total_human']).(!empty($day['overtime_human']) && $day['overtime_human'] !== '0m' ? ' · Fora do horário: '.esc_html($day['overtime_human']) : '').(!empty($day['can_add_store']) ? ' · Ainda cabe mais uma loja' : '').'</div>';
                echo '<ol style="margin:12px 0 0 18px">';
                foreach ((array)($day['items'] ?? []) as $item) {
                    echo '<li><strong>'.esc_html($item['name']).'</strong><br><span style="color:#64748b">'.esc_html($item['city']).' · '.esc_html($item['visit_duration_min']).' min · '.esc_html($item['visit_frequency']).($item['copy_index']>1 ? ' #'.intval($item['copy_index']) : '').'</span></li>';
                }
                echo '</ol></div>';
            }
            echo '</div>';
            echo '<div style="margin-top:14px;padding:14px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#334155">Resumo '.($plan_scope === 'monthly' ? 'mensal' : 'semanal').': '.intval($suggested['summary']['stops'] ?? 0).' visitas planeadas, '.esc_html($suggested['summary']['travel_human'] ?? '0m').' de viagem estimada, '.esc_html($suggested['summary']['visit_human'] ?? '0m').' em loja, '.esc_html($suggested['summary']['lunch_human'] ?? '0m').' de almoço, trabalho total '.esc_html($suggested['summary']['work_human'] ?? '0m').', dia total '.esc_html($suggested['summary']['total_human'] ?? '0m').(!empty($suggested['summary']['overtime_human']) && $suggested['summary']['overtime_human'] !== '0m' ? ', fora do horário '.esc_html($suggested['summary']['overtime_human']) : '').'.</div>';
            if (!empty($suggested['reinforcement']['recommended'])) {
                echo '<div style="margin-top:14px;padding:16px;border:1px solid #f59e0b;border-radius:16px;background:#fffbeb;color:#78350f"><strong>Reforço operacional sugerido.</strong> O plano ultrapassa o horário normal para cumprir a periodicidade. Limite máximo de horas extra por dia: <strong>' . esc_html((string)($suggested['reinforcement']['max_overtime_per_day_human'] ?? '2h 30m')) . '</strong>. Horas extra totais estimadas: <strong>' . esc_html((string)($suggested['reinforcement']['overtime_human'] ?? '0m')) . '</strong>'.(!empty($suggested['reinforcement']['zone']) ? ' · Zona prioritária para dividir a rota: <strong>' . esc_html((string)$suggested['reinforcement']['zone']) . '</strong>' : '').'. Carga sugerida para um 2.º membro: <strong>' . esc_html((string)($suggested['reinforcement']['second_member_share_human'] ?? '0m')) . '</strong>'.(!empty($suggested['reinforcement']['unassigned_count']) ? ' Existem <strong>' . intval($suggested['reinforcement']['unassigned_count']) . '</strong> visitas que já não cabem dentro do período sem reforço.' : '').'</div>';
            }
            if (!empty($suggested['unassigned'])) {
                echo '<div style="margin-top:12px;padding:14px;border:1px solid #fecaca;border-radius:16px;background:#fef2f2;color:#991b1b"><strong>Visitas por encaixar no período:</strong> ' . intval(count((array)$suggested['unassigned'])) . '. Para manter a periodicidade sem ultrapassar 2h30m de horas extra por dia, estas visitas devem ser absorvidas por reforço de equipa ou redistribuição operacional.</div>';
            }
        }
        return (string) ob_get_clean();
    }


    private static function get_campaign_linked_rows(int $project_id, array $filters = []): array {
        global $wpdb;
        if ($project_id <= 0) return [];
        $px = $wpdb->prefix . 'routespro_';
        $where = ['cl.project_id=%d'];
        $args = [$project_id];
        $q = sanitize_text_field((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.city LIKE %s OR l.phone LIKE %s OR l.postal_code LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $category_id = absint($filters['category_id'] ?? 0);
        if ($category_id > 0) {
            $where[] = '(l.category_id=%d OR l.subcategory_id=%d OR parent_cat.id=%d)';
            array_push($args, $category_id, $category_id, $category_id);
        }
        $status = sanitize_text_field((string)($filters['status'] ?? ''));
        if (in_array($status, ['active', 'paused'], true)) {
            $where[] = 'cl.status=%s';
            $args[] = $status;
        }
        $active = (string)($filters['active'] ?? '');
        if ($active === '1' || $active === '0') {
            $where[] = 'cl.is_active=%d';
            $args[] = (int)$active;
        }
        $owner_user_id = absint($filters['owner_user_id'] ?? 0);
        if ($owner_user_id > 0) {
            $where[] = 'cl.assigned_to=%d';
            $args[] = $owner_user_id;
        }
        $sql = "SELECT cl.id AS link_id, cl.status AS campaign_status, cl.priority, cl.visit_frequency, cl.frequency_count, cl.visit_duration_min, cl.assigned_to, cl.is_active AS campaign_active, l.*, c.name AS category_name, sc.name AS subcategory_name FROM {$px}campaign_locations cl INNER JOIN {$px}locations l ON l.id=cl.location_id LEFT JOIN {$px}categories c ON c.id=l.category_id LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id LEFT JOIN {$px}categories parent_cat ON parent_cat.id=l.subcategory_id WHERE " . implode(' AND ', $where) . " ORDER BY cl.priority DESC, l.city ASC, l.name ASC";
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
    }

    private static function stream_linked_locations_csv(array $project, array $rows, array $filters = []): void {
        if (ob_get_length()) @ob_end_clean();
        nocache_headers();
        $mode = !empty($filters['q']) || !empty($filters['category_id']) || !empty($filters['status']) || (string)($filters['active'] ?? '') !== '' || !empty($filters['owner_user_id']) ? 'filtrado' : 'todos';
        $filename = 'routespro-campanha-' . sanitize_title($project['name'] ?? 'campanha') . '-' . $mode . '-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fwrite($out, "ï»¿");
        fputcsv($out, [get_bloginfo('name')]);
        fputcsv($out, ['Campanha', (string)($project['name'] ?? '')]);
        fputcsv($out, ['Modo', $mode === 'filtrado' ? 'Resultado filtrado' : 'Todos os PDVs da campanha']);
        fputcsv($out, ['Pesquisa', (string)($filters['q'] ?? '')]);
        fputcsv($out, ['Categoria ID', (int)($filters['category_id'] ?? 0)]);
        fputcsv($out, ['Owner ID', (int)($filters['owner_user_id'] ?? 0)]);
        fputcsv($out, ['Estado campanha', (string)($filters['status'] ?? '')]);
        $activeLabel = (string)($filters['active'] ?? '') === '1' ? 'Ativas' : ((string)($filters['active'] ?? '') === '0' ? 'Inativas' : 'Todas');
        fputcsv($out, ['Ligação ativa', $activeLabel]);
        fputcsv($out, ['Total de linhas', count($rows)]);
        fputcsv($out, []);
        fputcsv($out, ['link_id','location_id','campanha','nome','morada','codigo_postal','cidade','distrito','telefone','email','categoria','subcategoria','owner_user_id','visit_frequency','frequency_count','visit_duration_min','priority','campaign_status','campaign_active','lat','lng','updated_at']);
        foreach ($rows as $row) {
            fputcsv($out, [
                (int)($row['link_id'] ?? 0),
                (int)($row['id'] ?? 0),
                (string)($project['name'] ?? ''),
                (string)($row['name'] ?? ''),
                (string)($row['address'] ?? ''),
                (string)($row['postal_code'] ?? ''),
                (string)($row['city'] ?? ''),
                (string)($row['district'] ?? ''),
                (string)($row['phone'] ?? ''),
                (string)($row['email'] ?? ''),
                (string)($row['category_name'] ?? ''),
                (string)($row['subcategory_name'] ?? ''),
                (int)($row['assigned_to'] ?? 0),
                (string)($row['visit_frequency'] ?? ''),
                (int)($row['frequency_count'] ?? 0),
                (int)($row['visit_duration_min'] ?? 0),
                (int)($row['priority'] ?? 0),
                (string)($row['campaign_status'] ?? ''),
                !empty($row['campaign_active']) ? '1' : '0',
                (string)($row['lat'] ?? ''),
                (string)($row['lng'] ?? ''),
                (string)($row['updated_at'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }

    private static function render_linked_pagination(int $project_id, int $category_id, string $q, string $week_start, string $plan_scope, string $holiday_country, int $owner_user_id, int $linked_page, int $linked_per_page, int $linked_count, array $simulation_options = [], string $linked_q = '', int $linked_category_id = 0, string $linked_status = '', string $linked_active = ''): string {
        $total_pages = max(1, (int) ceil($linked_count / max(1, $linked_per_page)));
        if ($total_pages <= 1) return '';
        $base = add_query_arg([
            'page' => 'routespro-campaign-locations',
            'project_id' => $project_id,
            'category_id' => $category_id,
            'q' => $q,
            'week_start' => $week_start,
            'plan_scope' => $plan_scope,
            'holiday_country' => $holiday_country,
            'owner_user_id' => $owner_user_id,
            'linked_per_page' => $linked_per_page,
            'linked_q' => $linked_q,
            'linked_category_id' => $linked_category_id,
            'linked_status' => $linked_status,
            'linked_active' => $linked_active,
            'simulation_max_stops' => (int)($simulation_options['max_stops_per_day'] ?? 12),
            'simulation_work_minutes' => (int)($simulation_options['work_minutes'] ?? 480),
            'simulation_lunch_minutes' => (int)($simulation_options['lunch_minutes'] ?? 60),
            'simulation_allow_overtime' => !empty($simulation_options['allow_overtime']) ? 1 : 0,
        ], admin_url('admin.php'));
        $html = '<div style="display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:12px">';
        $html .= '<div style="color:#64748b">Página ' . intval($linked_page) . ' de ' . intval($total_pages) . ', ' . intval($linked_count) . ' PDVs</div>';
        $html .= '<div style="display:flex;gap:6px;align-items:center">';
        if ($linked_page > 1) $html .= '<a class="button" href="' . esc_url(add_query_arg('linked_page', $linked_page - 1, $base)) . '">Anterior</a>';
        if ($linked_page < $total_pages) $html .= '<a class="button" href="' . esc_url(add_query_arg('linked_page', $linked_page + 1, $base)) . '">Seguinte</a>';
        $html .= '</div></div>';
        return $html;
    }

    private static function normalize_plan_options(array $raw = []): array {
        $work_minutes = (int)($raw['work_minutes'] ?? 480);
        if ($work_minutes <= 0 && !empty($raw['simulation_work_hours'])) $work_minutes = (int) round(((float)$raw['simulation_work_hours']) * 60);
        $dailyOvertime = array_values(array_filter(array_map('sanitize_text_field', (array)($raw['daily_overtime_dates'] ?? []))));
        $dailyOvertimeMinutes = [];
        foreach ((array)($raw['daily_overtime_minutes'] ?? []) as $date => $minsRaw) {
            $date = sanitize_text_field((string)$date);
            if (!$date) continue;
            if (!is_numeric($minsRaw)) continue;
            $mins = (int) round((float)$minsRaw);
            if ($mins <= 8) $mins = (int) round($mins * 60);
            $mins = max(0, min(150, $mins));
            if ($mins > 0) $dailyOvertimeMinutes[$date] = $mins;
        }
        $startPoint = is_array($raw['start_point'] ?? null) ? $raw['start_point'] : [];
        $endPoint = is_array($raw['end_point'] ?? null) ? $raw['end_point'] : [];
        return [
            'max_stops_per_day' => max(1, min(20, (int)($raw['max_stops_per_day'] ?? 12))),
            'work_minutes' => max(60, min(720, $work_minutes ?: 480)),
            'lunch_minutes' => max(0, min(180, (int)($raw['lunch_minutes'] ?? 60))),
            'allow_overtime' => !empty($raw['allow_overtime']),
            'overtime_extra_minutes' => max(0, min(150, (int)($raw['overtime_extra_minutes'] ?? 60))),
            'daily_overtime_dates' => $dailyOvertime,
            'daily_overtime_minutes' => $dailyOvertimeMinutes,
            'lock_start_point' => !empty($raw['lock_start_point']),
            'lock_end_point' => !empty($raw['lock_end_point']),
            'start_point' => [
                'address' => sanitize_text_field((string)($startPoint['address'] ?? '')),
                'lat' => is_numeric($startPoint['lat'] ?? null) ? (float)$startPoint['lat'] : null,
                'lng' => is_numeric($startPoint['lng'] ?? null) ? (float)$startPoint['lng'] : null,
            ],
            'end_point' => [
                'address' => sanitize_text_field((string)($endPoint['address'] ?? '')),
                'lat' => is_numeric($endPoint['lat'] ?? null) ? (float)$endPoint['lat'] : null,
                'lng' => is_numeric($endPoint['lng'] ?? null) ? (float)$endPoint['lng'] : null,
            ],
        ];
    }

    private static function build_period_plan(array $linked, string $scope, string $base_date, string $holiday_country = 'pt', array $options = []): array {
        $options = self::normalize_plan_options($options);
        return $scope === 'monthly' ? self::build_month_plan($linked, $base_date, $holiday_country, $options) : self::build_week_plan($linked, $base_date, $holiday_country, $options);
    }

    private static function build_month_plan(array $linked, string $base_date, string $holiday_country = 'pt', array $options = []): array {
        $options = self::normalize_plan_options($options);
        $baseTs = strtotime($base_date ?: date('Y-m-d'));
        if (!$baseTs) $baseTs = current_time('timestamp');
        $monthStart = date('Y-m-01', $baseTs);
        $monthEnd = date('Y-m-t', $baseTs);
        $holidayMap = self::get_holiday_map($holiday_country, [(int)date('Y', strtotime($monthStart)), (int)date('Y', strtotime($monthEnd))]);
        $excludedDays = [];
        $weekBuckets = [];
        $allWeekdays = [];
        for ($ts = strtotime($monthStart); $ts <= strtotime($monthEnd); $ts = strtotime('+1 day', $ts)) {
            $dow = (int) date('N', $ts);
            $date = date('Y-m-d', $ts);
            if ($dow >= 6) { $excludedDays[$date] = 'Fim de semana'; continue; }
            if (isset($holidayMap[$date])) { $excludedDays[$date] = $holidayMap[$date]; continue; }
            $weekKey = date('o-\WW', $ts);
            if (!isset($weekBuckets[$weekKey])) {
                $weekBuckets[$weekKey] = ['label' => 'Semana ' . (count($weekBuckets) + 1), 'dates' => []];
            }
            $weekBuckets[$weekKey]['dates'][] = $date;
            $allWeekdays[] = $date;
        }
        $weekKeys = array_keys($weekBuckets);
        $weekCount = max(1, count($weekKeys));
        $tasksByWeek = [];
        foreach ($linked as $row) {
            if (empty($row['campaign_active']) || ($row['campaign_status'] ?? 'active') !== 'active') continue;
            $lat = isset($row['lat']) ? (float)$row['lat'] : null;
            $lng = isset($row['lng']) ? (float)$row['lng'] : null;
            if (!is_finite($lat) || !is_finite($lng)) continue;
            $freq = ($row['visit_frequency'] ?: 'weekly');
            $count = max(1, (int)($row['frequency_count'] ?? 1));
            $copyIndexes = [];
            if ($freq === 'weekly') {
                foreach ($weekKeys as $wIdx => $weekKey) {
                    for ($i = 1; $i <= min(7, $count); $i++) {
                        $copyIndexes[] = ['week_key' => $weekKey, 'copy_index' => (($wIdx * max(1, $count)) + $i)];
                    }
                }
            } else {
                $monthlyCount = min(12, $count);
                for ($i = 0; $i < $monthlyCount; $i++) {
                    $targetWeekIdx = (int) floor(($i * $weekCount) / max(1, $monthlyCount));
                    $targetWeekIdx = max(0, min($weekCount - 1, $targetWeekIdx));
                    $copyIndexes[] = ['week_key' => $weekKeys[$targetWeekIdx] ?? ($weekKeys[0] ?? ''), 'copy_index' => $i + 1];
                }
            }
            foreach ($copyIndexes as $meta) {
                $copy = $row;
                $copy['copy_index'] = (int) $meta['copy_index'];
                $copy['visit_frequency'] = $freq;
                $copy['visit_duration_min'] = max(0, min(360, (int)($row['visit_duration_min'] ?? 45)));
                $copy['target_week_key'] = (string) $meta['week_key'];
                $tasksByWeek[(string) $meta['week_key']][] = $copy;
            }
        }
        if (!$tasksByWeek) return ['days' => [], 'summary' => [], 'scope' => 'monthly', 'period_label' => date_i18n('F Y', strtotime($monthStart)), 'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })), 'excluded_days' => $excludedDays];

        $days = [];
        $allUnassigned = [];
        $reinforcementWeeks = [];
        foreach ($weekBuckets as $weekKey => $bucket) {
            $planned = self::plan_tasks_into_dates((array)($tasksByWeek[$weekKey] ?? []), $bucket['dates'], $bucket['label'], $options);
            foreach ((array)($planned['days'] ?? []) as $day) $days[] = $day;
            foreach ((array)($planned['unassigned'] ?? []) as $left) $allUnassigned[] = $left;
            if (!empty($planned['reinforcement'])) $reinforcementWeeks[] = $planned['reinforcement'];
        }
        usort($days, function($a, $b){ return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')); });
        $summary = self::summarize_days($days, $allUnassigned, count($allWeekdays));
        $reinforcement = self::merge_reinforcement_summaries($reinforcementWeeks, $allUnassigned, $summary, count($allWeekdays), (int)$options['work_minutes']);
        return ['days' => $days, 'summary' => $summary, 'scope' => 'monthly', 'period_label' => date_i18n('F Y', strtotime($monthStart)), 'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })), 'excluded_days' => $excludedDays, 'options' => $options, 'unassigned' => $allUnassigned, 'reinforcement' => $reinforcement];
    }

    private static function build_week_plan(array $linked, string $base_date = '', string $holiday_country = 'pt', array $options = []): array {
        $tasks = [];
        foreach ($linked as $row) {
            if (empty($row['campaign_active']) || ($row['campaign_status'] ?? 'active') !== 'active') continue;
            $lat = isset($row['lat']) ? (float)$row['lat'] : null;
            $lng = isset($row['lng']) ? (float)$row['lng'] : null;
            if (!is_finite($lat) || !is_finite($lng)) continue;
            $freq = ($row['visit_frequency'] ?: 'weekly');
            $count = max(1, (int)($row['frequency_count'] ?? 1));
            $visits = $freq === 'weekly' ? min(7, $count) : 1;
            for ($i=1; $i<=$visits; $i++) {
                $copy = $row;
                $copy['copy_index'] = $i;
                $copy['visit_frequency'] = $freq;
                $copy['visit_duration_min'] = max(0, min(360, (int)($row['visit_duration_min'] ?? 45)));
                $tasks[] = $copy;
            }
        }
        if (!$tasks) return ['days'=>[], 'summary'=>[], 'scope' => 'weekly', 'excluded_holidays' => []];
        $baseTs = strtotime($base_date ?: date('Y-m-d'));
        if (!$baseTs) $baseTs = current_time('timestamp');
        $holidayMap = self::get_holiday_map($holiday_country, [(int)date('Y', $baseTs), (int)date('Y', strtotime('+21 day', $baseTs))]);
        $dates = [];
        $excludedDays = [];
        for ($i = 0; $i < 21 && count($dates) < 7; $i++) {
            $ts = strtotime('+' . $i . ' day', $baseTs);
            $date = date('Y-m-d', $ts);
            $dow = (int) date('N', $ts);
            if ($dow >= 6) { $excludedDays[$date] = 'Fim de semana'; continue; }
            if (isset($holidayMap[$date])) { $excludedDays[$date] = $holidayMap[$date]; continue; }
            $dates[] = $date;
        }
        if (!$dates) return ['days'=>[], 'summary'=>[], 'scope' => 'weekly', 'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })), 'excluded_days' => $excludedDays];
        $planned = self::plan_tasks_into_dates($tasks, $dates, 'Dia', $options);
        $days = (array)($planned['days'] ?? []);
        $unassigned = (array)($planned['unassigned'] ?? []);
        $summary = self::summarize_days($days, $unassigned, count($dates));
        return ['days'=>$days, 'summary'=>$summary, 'scope' => 'weekly', 'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })), 'excluded_days' => $excludedDays, 'options' => self::normalize_plan_options($options), 'unassigned' => $unassigned, 'reinforcement' => (array)($planned['reinforcement'] ?? [])];
    }

    private static function plan_tasks_into_dates(array $tasks, array $dates, string $label_prefix = 'Dia', array $options = []): array {
        if (!$tasks || !$dates) return ['days' => [], 'unassigned' => [], 'reinforcement' => []];
        $options = self::normalize_plan_options($options);
        $maxStops = (int) $options['max_stops_per_day'];
        $targetWorkMin = (int) $options['work_minutes'];
        $lunchMin = (int) $options['lunch_minutes'];
        $globalAllowOvertime = !empty($options['allow_overtime']);
        $defaultExtraMin = min(150, (int)($options['overtime_extra_minutes'] ?? 60));
        $dailyOvertimeDates = array_flip((array)($options['daily_overtime_dates'] ?? []));
        $dailyOvertimeMinutes = (array)($options['daily_overtime_minutes'] ?? []);
        $startPoint = is_array($options['start_point'] ?? null) ? $options['start_point'] : [];
        $endPoint = is_array($options['end_point'] ?? null) ? $options['end_point'] : [];
        usort($tasks, function($a,$b){
            $pa = (int)($a['priority'] ?? 0); $pb = (int)($b['priority'] ?? 0);
            if ($pa !== $pb) return $pb <=> $pa;
            $fa = (($a['visit_frequency'] ?? 'weekly') === 'monthly') ? 1 : 0;
            $fb = (($b['visit_frequency'] ?? 'weekly') === 'monthly') ? 1 : 0;
            if ($fa !== $fb) return $fa <=> $fb;
            $ka = strtolower(trim(($a['district'] ?? '').'|'.($a['city'] ?? '').'|'.($a['name'] ?? '')));
            $kb = strtolower(trim(($b['district'] ?? '').'|'.($b['city'] ?? '').'|'.($b['name'] ?? '')));
            return $ka <=> $kb;
        });
        $days = [];
        $taskPool = array_values($tasks);
        foreach (array_values($dates) as $idx => $date) {
            if (!$taskPool) break;
            $dayExtraMin = isset($dailyOvertimeMinutes[$date]) ? min(150, (int)$dailyOvertimeMinutes[$date]) : (isset($dailyOvertimeDates[$date]) ? $defaultExtraMin : 0);
            $allowOvertime = $globalAllowOvertime || $dayExtraMin > 0 || isset($dailyOvertimeDates[$date]);
            $hardWorkLimit = $targetWorkMin + min(150, max(0, $dayExtraMin));
            $day = [
                'label'=>$label_prefix . ' · ' . ($idx + 1),
                'date'=>$date,
                'items'=>[],
                'travel_min'=>0.0,
                'visit_min'=>0,
                'stops'=>0,
                'last'=>null,
                'start_point'=>$startPoint,
                'end_point'=>$endPoint,
                'allow_overtime'=>$allowOvertime,
                'extra_minutes'=>$dayExtraMin,
                'hard_limit_minutes'=>$hardWorkLimit,
            ];
            $seedIndex = self::find_best_seed_index($taskPool, $days, $startPoint);
            $seed = $taskPool[$seedIndex];
            array_splice($taskPool, $seedIndex, 1);
            $seedTravel = 0.0;
            if (is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null) && is_numeric($seed['lat'] ?? null) && is_numeric($seed['lng'] ?? null)) {
                $seedTravel = self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$seed['lat'], (float)$seed['lng']) / 45 * 60;
            }
            $day['items'][] = $seed;
            $day['visit_min'] += (int)$seed['visit_duration_min'];
            $day['travel_min'] += $seedTravel;
            $day['stops']++;
            $day['last'] = $seed;
            while ($taskPool) {
                $bestIndex = null; $bestScore = PHP_FLOAT_MAX;
                foreach ($taskPool as $i => $candidate) {
                    $distKm = self::haversine_km((float)$day['last']['lat'], (float)$day['last']['lng'], (float)$candidate['lat'], (float)$candidate['lng']);
                    $travelMin = $distKm / 45 * 60;
                    $nextVisit = $day['visit_min'] + (int)$candidate['visit_duration_min'];
                    $nextTravel = $day['travel_min'] + $travelMin;
                    $nextStops = $day['stops'] + 1;
                    $returnTravel = 0.0;
                    if (is_numeric($endPoint['lat'] ?? null) && is_numeric($endPoint['lng'] ?? null)) {
                        $returnTravel = self::haversine_km((float)$candidate['lat'], (float)$candidate['lng'], (float)$endPoint['lat'], (float)$endPoint['lng']) / 45 * 60;
                    }
                    $nextWork = $nextVisit + $nextTravel + $returnTravel;
                    if ($nextStops > $maxStops || $nextTravel > 300) continue;
                    if ($nextWork > $hardWorkLimit) continue;
                    $zonePenalty = self::same_zone_score($day['last'], $candidate) ? 0 : 45;
                    $balancePenalty = max(0, $nextWork - $targetWorkMin);
                    $score = ($distKm * 2.0) + $zonePenalty + ($balancePenalty * 1.2) + max(0, ($targetWorkMin * 0.82) - $nextWork);
                    if ($allowOvertime && $nextWork > $targetWorkMin) $score += (($nextWork - $targetWorkMin) * 0.8);
                    if ($score < $bestScore) { $bestScore = $score; $bestIndex = $i; }
                }
                if ($bestIndex === null) break;
                $chosen = $taskPool[$bestIndex];
                $distKm = self::haversine_km((float)$day['last']['lat'], (float)$day['last']['lng'], (float)$chosen['lat'], (float)$chosen['lng']);
                $day['travel_min'] += $distKm / 45 * 60;
                $day['visit_min'] += (int)$chosen['visit_duration_min'];
                $day['stops']++;
                $day['items'][] = $chosen;
                $day['last'] = $chosen;
                array_splice($taskPool, $bestIndex, 1);
                $currentWork = (int) round($day['travel_min'] + $day['visit_min']);
                if ($day['stops'] >= $maxStops) break;
                if ($currentWork >= ($hardWorkLimit - 20)) break;
            }
            unset($day['last']);
            self::finalize_planned_day($day, $targetWorkMin, $lunchMin);
            $days[] = $day;
        }
        if ($taskPool && $days) {
            foreach ($taskPool as $leftIndex => $left) {
                $bestDay = null;
                $bestScore = PHP_FLOAT_MAX;
                foreach ($days as $i => $day) {
                    $dayHardLimit = (int)($day['hard_limit_minutes'] ?? ($targetWorkMin + min(150, (int)($day['extra_minutes'] ?? $defaultExtraMin))));
                    if ((int)($day['stops'] ?? 0) >= $maxStops) continue;
                    $addedTravel = 0.0;
                    $lastItem = !empty($day['items']) ? end($day['items']) : null;
                    if (is_array($lastItem) && is_numeric($lastItem['lat'] ?? null) && is_numeric($lastItem['lng'] ?? null) && is_numeric($left['lat'] ?? null) && is_numeric($left['lng'] ?? null)) {
                        $addedTravel += self::haversine_km((float)$lastItem['lat'], (float)$lastItem['lng'], (float)$left['lat'], (float)$left['lng']) / 45 * 60;
                    }
                    if (is_numeric($day['end_point']['lat'] ?? null) && is_numeric($day['end_point']['lng'] ?? null) && is_numeric($left['lat'] ?? null) && is_numeric($left['lng'] ?? null)) {
                        $addedTravel += self::haversine_km((float)$left['lat'], (float)$left['lng'], (float)$day['end_point']['lat'], (float)$day['end_point']['lng']) / 45 * 60;
                    }
                    $nextWork = (int) round(($day['travel_min'] ?? 0) + ($day['visit_min'] ?? 0) + $addedTravel + (int)($left['visit_duration_min'] ?? 45));
                    if ($nextWork > $dayHardLimit) continue;
                    $score = abs($targetWorkMin - $nextWork) + (self::same_zone_score($lastItem, $left) ? 0 : 40);
                    if ($score < $bestScore) { $bestScore = $score; $bestDay = $i; }
                }
                if ($bestDay === null) continue;
                $lastItem = !empty($days[$bestDay]['items']) ? end($days[$bestDay]['items']) : null;
                if (is_array($lastItem) && is_numeric($lastItem['lat'] ?? null) && is_numeric($lastItem['lng'] ?? null) && is_numeric($left['lat'] ?? null) && is_numeric($left['lng'] ?? null)) {
                    $days[$bestDay]['travel_min'] += self::haversine_km((float)$lastItem['lat'], (float)$lastItem['lng'], (float)$left['lat'], (float)$left['lng']) / 45 * 60;
                }
                $days[$bestDay]['items'][] = $left;
                $days[$bestDay]['visit_min'] += (int)($left['visit_duration_min'] ?? 45);
                $days[$bestDay]['stops']++;
                self::finalize_planned_day($days[$bestDay], $targetWorkMin, $lunchMin);
                unset($taskPool[$leftIndex]);
            }
            $taskPool = array_values($taskPool);
        }
        foreach ($days as &$day) unset($day['hard_limit_minutes']);
        unset($day);
        $reinforcement = self::build_reinforcement_summary($days, $taskPool, $targetWorkMin, count($dates));
        return ['days' => $days, 'unassigned' => $taskPool, 'reinforcement' => $reinforcement];
    }

    private static function finalize_planned_day(array &$day, int $targetWorkMin, int $lunchMin): void {
        $returnMin = 0.0;
        $items = (array)($day['items'] ?? []);
        $lastItem = $items ? end($items) : null;
        if (is_array($lastItem) && is_numeric($day['end_point']['lat'] ?? null) && is_numeric($day['end_point']['lng'] ?? null) && is_numeric($lastItem['lat'] ?? null) && is_numeric($lastItem['lng'] ?? null)) {
            $returnMin = self::haversine_km((float)$lastItem['lat'], (float)$lastItem['lng'], (float)$day['end_point']['lat'], (float)$day['end_point']['lng']) / 45 * 60;
        }
        $day['return_min'] = $returnMin;
        $workMin = (int) round(($day['travel_min'] ?? 0) + $returnMin + ($day['visit_min'] ?? 0));
        $overtimeMin = max(0, $workMin - $targetWorkMin);
        $lunchToApply = !empty($day['items']) ? $lunchMin : 0;
        $day['work_min'] = $workMin;
        $day['lunch_min'] = $lunchToApply;
        $day['overtime_min'] = $overtimeMin;
        $day['travel_human'] = self::human_minutes((int)round(($day['travel_min'] ?? 0) + $returnMin));
        $day['visit_human'] = self::human_minutes((int)($day['visit_min'] ?? 0));
        $day['work_human'] = self::human_minutes($workMin);
        $day['lunch_human'] = self::human_minutes($lunchToApply);
        $day['overtime_human'] = self::human_minutes($overtimeMin);
        $day['total_human'] = self::human_minutes($workMin + $lunchToApply);
        $day['can_add_store'] = ((int)($day['stops'] ?? 0) < 20) && ($workMin + 30 <= ($targetWorkMin + (int)($day['extra_minutes'] ?? 0)));
    }

    private static function summarize_days(array $days, array $unassigned = [], int $periodDays = 0): array {
        $summary = ['stops'=>0,'travel_min'=>0,'visit_min'=>0,'work_min'=>0,'lunch_min'=>0,'overtime_min'=>0,'unassigned_count'=>count($unassigned),'period_days'=>$periodDays];
        foreach ($days as $d) {
            $summary['stops'] += (int)($d['stops'] ?? 0);
            $summary['travel_min'] += (int)round($d['travel_min'] ?? 0);
            $summary['visit_min'] += (int)($d['visit_min'] ?? 0);
            $summary['work_min'] += (int)($d['work_min'] ?? round(($d['travel_min'] ?? 0) + ($d['visit_min'] ?? 0)));
            $summary['lunch_min'] += (int)($d['lunch_min'] ?? 0);
            $summary['overtime_min'] += (int)($d['overtime_min'] ?? 0);
        }
        $summary['travel_human'] = self::human_minutes($summary['travel_min']);
        $summary['visit_human'] = self::human_minutes($summary['visit_min']);
        $summary['work_human'] = self::human_minutes($summary['work_min']);
        $summary['lunch_human'] = self::human_minutes($summary['lunch_min']);
        $summary['overtime_human'] = self::human_minutes($summary['overtime_min']);
        $summary['total_human'] = self::human_minutes($summary['work_min'] + $summary['lunch_min']);
        return $summary;
    }

    private static function same_zone_score($a, $b): bool {
        if (!is_array($a) || !is_array($b)) return false;
        $districtA = strtolower(trim((string)($a['district'] ?? '')));
        $districtB = strtolower(trim((string)($b['district'] ?? '')));
        $cityA = strtolower(trim((string)($a['city'] ?? '')));
        $cityB = strtolower(trim((string)($b['city'] ?? '')));
        if ($districtA !== '' && $districtA === $districtB) return true;
        return $cityA !== '' && $cityA === $cityB;
    }

    private static function find_best_seed_index(array $taskPool, array $existingDays, array $startPoint = []): int {
        if (!$taskPool) return 0;
        $zoneLoad = [];
        foreach ($existingDays as $day) {
            foreach ((array)($day['items'] ?? []) as $item) {
                $zone = self::task_zone_key($item);
                if ($zone === '') continue;
                $zoneLoad[$zone] = ($zoneLoad[$zone] ?? 0) + 1;
            }
        }
        $bestIndex = 0;
        $bestScore = PHP_FLOAT_MAX;
        foreach (array_values($taskPool) as $i => $task) {
            $zone = self::task_zone_key($task);
            $score = (float)($zoneLoad[$zone] ?? 0) * 50;
            if (is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null) && is_numeric($task['lat'] ?? null) && is_numeric($task['lng'] ?? null)) {
                $score += self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$task['lat'], (float)$task['lng']);
            }
            $score -= (float)((int)($task['priority'] ?? 0)) * 3;
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $i;
            }
        }
        return $bestIndex;
    }

    private static function task_zone_key(array $task): string {
        $district = trim((string)($task['district'] ?? ''));
        $city = trim((string)($task['city'] ?? ''));
        if ($district !== '' || $city !== '') return strtolower($district . '|' . $city);
        return strtolower(trim((string)($task['address'] ?? '')));
    }

    private static function pick_reinforcement_zone(array $tasks): string {
        if (!$tasks) return '';
        $counts = [];
        foreach ($tasks as $task) {
            $district = trim((string)($task['district'] ?? ''));
            $city = trim((string)($task['city'] ?? ''));
            $label = $district !== '' && $city !== '' ? $district . ' / ' . $city : ($district !== '' ? $district : ($city !== '' ? $city : trim((string)($task['address'] ?? ''))));
            if ($label === '') continue;
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        if (!$counts) return '';
        arsort($counts);
        return (string)array_key_first($counts);
    }

    private static function build_reinforcement_summary(array $days, array $unassigned, int $targetWorkMin, int $periodDays): array {
        $summary = self::summarize_days($days, $unassigned, $periodDays);
        $maxOvertimePerDay = 150;
        $peakDay = null;
        foreach ($days as $day) {
            if ($peakDay === null || (int)($day['overtime_min'] ?? 0) > (int)($peakDay['overtime_min'] ?? 0)) {
                $peakDay = $day;
            }
        }
        $zoneSource = $unassigned;
        if (!$zoneSource && is_array($peakDay)) $zoneSource = (array)($peakDay['items'] ?? []);
        $zone = self::pick_reinforcement_zone($zoneSource);
        $overloadedDays = 0;
        foreach ($days as $day) {
            if ((int)($day['overtime_min'] ?? 0) > 0) $overloadedDays++;
        }
        $requiredExtraMinutes = 0;
        foreach ($unassigned as $task) {
            $requiredExtraMinutes += (int)($task['visit_duration_min'] ?? 45) + 20;
        }
        $requiredExtraMinutes += max(0, $summary['overtime_min'] - ($overloadedDays * $maxOvertimePerDay));
        $recommended = !empty($unassigned) || $summary['overtime_min'] > 0;
        return [
            'recommended' => $recommended,
            'zone' => $zone,
            'normal_minutes' => $targetWorkMin * max(1, $periodDays),
            'normal_human' => self::human_minutes($targetWorkMin * max(1, $periodDays)),
            'overtime_minutes' => (int)$summary['overtime_min'],
            'overtime_human' => self::human_minutes((int)$summary['overtime_min']),
            'max_overtime_per_day_minutes' => $maxOvertimePerDay,
            'max_overtime_per_day_human' => self::human_minutes($maxOvertimePerDay),
            'overloaded_days' => $overloadedDays,
            'unassigned_count' => count($unassigned),
            'unassigned_minutes' => max(0, $requiredExtraMinutes),
            'unassigned_human' => self::human_minutes(max(0, $requiredExtraMinutes)),
            'second_member_share_minutes' => (int)max($summary['overtime_min'], $requiredExtraMinutes),
            'second_member_share_human' => self::human_minutes((int)max($summary['overtime_min'], $requiredExtraMinutes)),
            'ratio_vs_normal' => $targetWorkMin > 0 && $periodDays > 0 ? round(((int)$summary['overtime_min'] / ($targetWorkMin * $periodDays)) * 100, 1) : 0,
        ];
    }

    private static function merge_reinforcement_summaries(array $weeks, array $unassigned, array $summary, int $periodDays, int $targetWorkMin): array {
        if (!$weeks) return self::build_reinforcement_summary([], $unassigned, $targetWorkMin, $periodDays);
        $zoneCounts = [];
        $overloadedDays = 0;
        foreach ($weeks as $week) {
            if (!empty($week['zone'])) $zoneCounts[$week['zone']] = ($zoneCounts[$week['zone']] ?? 0) + 1;
            $overloadedDays += (int)($week['overloaded_days'] ?? 0);
        }
        arsort($zoneCounts);
        $zone = $zoneCounts ? (string)array_key_first($zoneCounts) : self::pick_reinforcement_zone($unassigned);
        return [
            'recommended' => !empty($unassigned) || (int)($summary['overtime_min'] ?? 0) > 0,
            'zone' => $zone,
            'normal_minutes' => $targetWorkMin * max(1, $periodDays),
            'normal_human' => self::human_minutes($targetWorkMin * max(1, $periodDays)),
            'overtime_minutes' => (int)($summary['overtime_min'] ?? 0),
            'overtime_human' => self::human_minutes((int)($summary['overtime_min'] ?? 0)),
            'max_overtime_per_day_minutes' => 150,
            'max_overtime_per_day_human' => self::human_minutes(150),
            'overloaded_days' => $overloadedDays,
            'unassigned_count' => count($unassigned),
            'unassigned_minutes' => 0,
            'unassigned_human' => self::human_minutes(0),
            'second_member_share_minutes' => max((int)($summary['overtime_min'] ?? 0), count($unassigned) * 65),
            'second_member_share_human' => self::human_minutes(max((int)($summary['overtime_min'] ?? 0), count($unassigned) * 65)),
            'ratio_vs_normal' => $targetWorkMin > 0 && $periodDays > 0 ? round(((int)($summary['overtime_min'] ?? 0) / ($targetWorkMin * $periodDays)) * 100, 1) : 0,
        ];
    }

    private static function stream_plan_csv(array $project, array $plan, string $scope, string $base_date, string $holiday_country = 'pt', array $simulation_options = []): void {
        $simulation_options = self::normalize_plan_options($simulation_options ?: (array)($plan['options'] ?? []));
        $filename = 'routespro-plan-' . sanitize_title($project['name'] ?? 'campanha') . '-' . $scope . '-' . date('Ymd', strtotime($base_date ?: date('Y-m-d'))) . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        if (!$out) exit;
        fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, [get_bloginfo('name')]);
        fputcsv($out, ['Campanha', (string)($project['name'] ?? '')]);
        fputcsv($out, ['Plano', $scope]);
        fputcsv($out, ['Data base', $base_date]);
        fputcsv($out, ['Feriados', strtoupper($holiday_country) === 'ES' ? 'Espanha' : 'Portugal']);
        fputcsv($out, ['Folgas automáticas', 'Fins de semana e feriados']);
        fputcsv($out, ['Máx. visitas/dia', (int)$simulation_options['max_stops_per_day']]);
        fputcsv($out, ['Horas úteis', self::human_minutes((int)$simulation_options['work_minutes'])]);
        fputcsv($out, ['Almoço', self::human_minutes((int)$simulation_options['lunch_minutes'])]);
        fputcsv($out, ['Permitir fora do horário, geral', !empty($simulation_options['allow_overtime']) ? 'Sim' : 'Não']);
        fputcsv($out, ['Horas adicionais, geral', self::human_minutes((int)($simulation_options['overtime_extra_minutes'] ?? 0))]);
        $dayExtras = []; foreach ((array)($simulation_options['daily_overtime_minutes'] ?? []) as $date => $mins) { $dayExtras[] = $date . ' (' . self::human_minutes((int)$mins) . ')'; }
        fputcsv($out, ['Dias com fora de horas', implode(' | ', (array)($simulation_options['daily_overtime_dates'] ?? []))]);
        fputcsv($out, ['Horas adicionais por dia', implode(' | ', $dayExtras)]);
        fputcsv($out, ['Ponto de partida', (string)($simulation_options['start_point']['address'] ?? '')]);
        fputcsv($out, ['Bloquear partida', !empty($simulation_options['lock_start_point']) ? 'Sim' : 'Não']);
        fputcsv($out, ['Ponto de chegada', (string)($simulation_options['end_point']['address'] ?? '')]);
        fputcsv($out, ['Bloquear chegada', !empty($simulation_options['lock_end_point']) ? 'Sim' : 'Não']);
        $excludedDays = (array)($plan['excluded_days'] ?? []);
        if ($excludedDays) {
            $parts = [];
            foreach ($excludedDays as $date => $reason) $parts[] = $date . ' (' . $reason . ')';
            fputcsv($out, ['Datas excluídas', implode(' | ', $parts)]);
        }
        $reinforcement = (array)($plan['reinforcement'] ?? []);
        if ($reinforcement) {
            fputcsv($out, ['Reforço sugerido', !empty($reinforcement['recommended']) ? 'Sim' : 'Não']);
            fputcsv($out, ['Zona prioritária reforço', (string)($reinforcement['zone'] ?? '')]);
            fputcsv($out, ['Horas extra totais estimadas', (string)($reinforcement['overtime_human'] ?? '0m')]);
            fputcsv($out, ['Carga sugerida para 2.º membro', (string)($reinforcement['second_member_share_human'] ?? '0m')]);
            fputcsv($out, ['Visitas por encaixar', (int)($reinforcement['unassigned_count'] ?? 0)]);
        }
        fputcsv($out, []);
        fputcsv($out, ['plano','data','bloco','fora_de_horas_no_dia','stops_no_bloco','trabalho_bloco','almoco_bloco','total_bloco','nome','morada','cidade','distrito','categoria','subcategoria','periodicidade','repeticao','duracao_visita_min','prioridade','travel_min_bloco']);
        foreach ((array)($plan['days'] ?? []) as $day) {
            $items = (array)($day['items'] ?? []);
            foreach ($items as $item) {
                fputcsv($out, [
                    $scope,
                    (string)($day['date'] ?? ''),
                    (string)($day['label'] ?? ''),
                    !empty($day['allow_overtime']) ? 'Sim' : 'Não',
                    (int)($day['stops'] ?? 0),
                    (string)($day['work_human'] ?? ''),
                    (string)($day['lunch_human'] ?? ''),
                    (string)($day['total_human'] ?? ''),
                    (string)($item['name'] ?? ''),
                    (string)($item['address'] ?? ''),
                    (string)($item['city'] ?? ''),
                    (string)($item['district'] ?? ''),
                    (string)($item['category_name'] ?? ''),
                    (string)($item['subcategory_name'] ?? ''),
                    (string)($item['visit_frequency'] ?? ''),
                    (int)($item['copy_index'] ?? 1),
                    (int)($item['visit_duration_min'] ?? 45),
                    (int)($item['priority'] ?? 0),
                    (int)round($day['travel_min'] ?? 0),
                ]);
            }
        }
        fclose($out);
        exit;
    }

    private static function create_routes_from_plan(int $client_id, int $project_id, int $owner_user_id, string $week_start, array $plan): int {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $days = (array)($plan['days'] ?? []);
        if (!$days) return 0;
        $defs = get_option('routespro_route_defaults', []);
        if (!is_array($defs)) $defs = [];
        $routeDefaults = $defs[$client_id . '|' . $project_id . '|' . $owner_user_id] ?? $defs[$client_id . '|' . $project_id . '|0'] ?? [];
        $planOptions = self::normalize_plan_options((array)($plan['options'] ?? []));
        $base = strtotime($week_start ?: date('Y-m-d'));
        if (!$base) $base = current_time('timestamp');
        $created = 0;
        foreach ($days as $idx => $day) {
            $date = !empty($day['date']) ? sanitize_text_field((string)$day['date']) : date('Y-m-d', strtotime('+' . $idx . ' day', $base));
            $meta = [
                'district' => '',
                'county' => '',
                'city' => '',
                'category_id' => 0,
                'subcategory_id' => 0,
                'generated_week_plan' => 1,
                'start_point' => $planOptions['start_point']['address'] || $planOptions['start_point']['lat'] !== null || $planOptions['start_point']['lng'] !== null ? $planOptions['start_point'] : ($routeDefaults['start_point'] ?? ['address'=>'','lat'=>null,'lng'=>null]),
                'end_point' => $planOptions['end_point']['address'] || $planOptions['end_point']['lat'] !== null || $planOptions['end_point']['lng'] !== null ? $planOptions['end_point'] : ($routeDefaults['end_point'] ?? ['address'=>'','lat'=>null,'lng'=>null]),
                'lock_start_point' => !empty($planOptions['lock_start_point']),
                'lock_end_point' => !empty($planOptions['lock_end_point']),
                'plan_summary' => [
                    'travel_min' => (int) round($day['travel_min'] ?? 0),
                    'visit_min' => (int) ($day['visit_min'] ?? 0),
                    'stops' => (int) ($day['stops'] ?? 0),
                    'overtime_min' => (int) ($day['overtime_min'] ?? 0),
                ],
            ];
            $wpdb->insert($px . 'routes', [
                'client_id' => $client_id,
                'project_id' => $project_id,
                'date' => $date,
                'status' => 'planned',
                'owner_user_id' => $owner_user_id ?: null,
                'meta_json' => wp_json_encode($meta),
            ]);
            $route_id = (int) $wpdb->insert_id;
            if (!$route_id) continue;
            foreach ((array)($day['items'] ?? []) as $seq => $item) {
                $wpdb->insert($px . 'route_stops', [
                    'route_id' => $route_id,
                    'location_id' => (int) ($item['id'] ?? 0),
                    'seq' => $seq,
                    'status' => 'pending',
                    'meta_json' => wp_json_encode([
                        'visit_time_min' => (int) ($item['visit_duration_min'] ?? 45),
                        'visit_time_mode' => 'bucket',
                        'campaign_frequency' => (string) ($item['visit_frequency'] ?? 'weekly'),
                        'copy_index' => (int) ($item['copy_index'] ?? 1),
                    ]),
                ]);
            }
            $created++;
        }
        return $created;
    }


    private static function get_holiday_map(string $country, array $years): array {
        $country = strtolower($country);
        if (!in_array($country, ['pt','es'], true)) $country = 'pt';
        $map = [];
        foreach (array_unique(array_map('intval', $years)) as $year) {
            if ($year < 2000 || $year > 2100) continue;
            foreach (self::get_holidays_for_year($country, $year) as $date => $label) $map[$date] = $label;
        }
        ksort($map);
        return $map;
    }

    private static function get_holidays_for_year(string $country, int $year): array {
        $easter = easter_date($year);
        $map = [];
        $add = function(string $date, string $label) use (&$map) { $map[$date] = $label; };
        if ($country === 'es') {
            $add(sprintf('%04d-01-01', $year), 'Año Nuevo');
            $add(sprintf('%04d-01-06', $year), 'Epifanía del Señor');
            $add(date('Y-m-d', strtotime('-2 day', $easter)), 'Viernes Santo');
            $add(sprintf('%04d-05-01', $year), 'Fiesta del Trabajo');
            $add(sprintf('%04d-08-15', $year), 'Asunción de la Virgen');
            $add(sprintf('%04d-10-12', $year), 'Fiesta Nacional de España');
            $add(sprintf('%04d-11-01', $year), 'Todos los Santos');
            $add(sprintf('%04d-12-06', $year), 'Día de la Constitución Española');
            $add(sprintf('%04d-12-08', $year), 'Inmaculada Concepción');
            $add(sprintf('%04d-12-25', $year), 'Natividad del Señor');
        } else {
            $add(sprintf('%04d-01-01', $year), 'Ano Novo');
            $add(date('Y-m-d', strtotime('-2 day', $easter)), 'Sexta-Feira Santa');
            $add(date('Y-m-d', $easter), 'Domingo de Páscoa');
            $add(sprintf('%04d-04-25', $year), 'Dia da Liberdade');
            $add(sprintf('%04d-05-01', $year), 'Dia do Trabalhador');
            $add(date('Y-m-d', strtotime('+60 day', $easter)), 'Corpo de Deus');
            $add(sprintf('%04d-06-10', $year), 'Dia de Portugal');
            $add(sprintf('%04d-08-15', $year), 'Assunção de Nossa Senhora');
            $add(sprintf('%04d-10-05', $year), 'Implantação da República');
            $add(sprintf('%04d-11-01', $year), 'Dia de Todos os Santos');
            $add(sprintf('%04d-12-01', $year), 'Restauração da Independência');
            $add(sprintf('%04d-12-08', $year), 'Imaculada Conceição');
            $add(sprintf('%04d-12-25', $year), 'Natal');
        }
        ksort($map);
        return $map;
    }

    private static function human_minutes(int $mins): string {
        $h = floor($mins / 60); $m = $mins % 60;
        if ($h <= 0) return $m . 'm';
        return $h . 'h ' . $m . 'm';
    }

    private static function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $earth * $c;
    }
}
