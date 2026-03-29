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

        // NOTE: Mantém-se normalize_plan_options() aqui, como tinhas.
        // A correção para "preservar options extra" é feita no build_period_plan() (bloco onde ele existir).
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

                // NOTE: Também aqui mantém-se como tinhas.
                // A correção "preservar extras" é no build_period_plan().
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
            echo '<script>(function(){const form=document.getElementById("routespro-bulk-linked-form"); if(!form) return; const badge=document.getElementById("routespro-bulk-dirty"); let dirty=false; const markDirty=(el)=>{ dirty=true; if(badge) badge.style.display="inline-flex"; const row=el && el.closest("tr[data-linked-row]"); if(row){ row.style.background="#fff7ed"; } }; form.querySelectorAll("[data-bulk-field=\\"1\\"]").forEach(el=>{ const ev=(el.type==="checkbox"||el.tagName==="SELECT")?"change":"input"; el.addEventListener(ev,()=>markDirty(el)); }); window.addEventListener("beforeunload",function(e){ if(!dirty) return; e.preventDefault(); e.returnValue=""; }); form.addEventListener("submit",function(){ dirty=false; if(badge) badge.style.display="none"; });})();</script>';
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
            echo '<script>(function(){const form=document.getElementById("routespro-plan-form"); if(!form) return; const preview=document.getElementById("routespro-plan-preview"); const syncReadonly=()=>{ const lockStart=form.querySelector("input[name=simulation_lock_start_point]"); const lockEnd=form.querySelector("input[name=simulation_lock_end_point]"); const s=form.querySelector("input[name=simulation_start_address]"); const e=form.querySelector("input[name=simulation_end_address]"); if(s&&lockStart) s.readOnly=!!lockStart.checked; if(e&&lockEnd) e.readOnly=!!lockEnd.checked; }; const syncExtra=()=>{ const extra=form.querySelector("input[name=simulation_overtime_extra_hours]"); const hidden=form.querySelector("input[name=simulation_overtime_extra_minutes]"); if(extra&&hidden){ const v=parseFloat(String(extra.value).replace(",","."))||0; hidden.value=String(Math.round(Math.max(0,Math.min(2.5,v))*60)); } form.querySelectorAll(`input[name^="simulation_overtime_minutes["]`).forEach(function(el){ const v=parseFloat(String(el.value).replace(",","."))||0; el.dataset.minutes=String(Math.round(Math.max(0,Math.min(2.5,v))*60)); }); }; let t=null; const schedule=()=>{ clearTimeout(t); t=setTimeout(run,180); }; const run=()=>{ syncReadonly(); syncExtra(); if(!preview) return; const fd=new FormData(); fd.append("action","routespro_campaign_plan_preview"); fd.append("project_id",'.intval($project_id).'); fd.append("owner_user_id",form.querySelector("select[name=owner_user_id]")?.value||"0"); fd.append("plan_scope",form.querySelector("select[name=plan_scope]")?.value||"weekly"); fd.append("holiday_country",form.querySelector("select[name=holiday_country]")?.value||"pt"); fd.append("week_start",form.querySelector("input[name=week_start]")?.value||""); fd.append("simulation_max_stops",form.querySelector("input[name=simulation_max_stops]")?.value||"12"); const workHours=parseFloat(form.querySelector("input[name=simulation_work_hours]")?.value||"8")||8; fd.append("simulation_work_minutes",String(Math.round(workHours*60))); fd.append("simulation_lunch_minutes",form.querySelector("input[name=simulation_lunch_minutes]")?.value||"60"); fd.append("simulation_overtime_extra_minutes",form.querySelector("input[name=simulation_overtime_extra_minutes]")?.value||"0"); fd.append("simulation_start_address",form.querySelector("input[name=simulation_start_address]")?.value||""); fd.append("simulation_start_lat",form.querySelector("input[name=simulation_start_lat]")?.value||""); fd.append("simulation_start_lng",form.querySelector("input[name=simulation_start_lng]")?.value||""); fd.append("simulation_end_address",form.querySelector("input[name=simulation_end_address]")?.value||""); fd.append("simulation_end_lat",form.querySelector("input[name=simulation_end_lat]")?.value||""); fd.append("simulation_end_lng",form.querySelector("input[name=simulation_end_lng]")?.value||""); if(form.querySelector("input[name=simulation_allow_overtime]")?.checked){ fd.append("simulation_allow_overtime","1"); } if(form.querySelector("input[name=simulation_lock_start_point]")?.checked){ fd.append("simulation_lock_start_point","1"); } if(form.querySelector("input[name=simulation_lock_end_point]")?.checked){ fd.append("simulation_lock_end_point","1"); } form.querySelectorAll(`input[name="simulation_overtime_dates[]"]:checked`).forEach(el=>fd.append("simulation_overtime_dates[]", el.value)); form.querySelectorAll(`input[name^="simulation_overtime_minutes["]`).forEach(el=>{ const m=el.dataset.minutes||"0"; if(m!=="0") fd.append(el.name, m); }); fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:fd}).then(r=>r.json()).then(resp=>{ if(resp&&resp.success&&resp.data&&resp.data.html!==undefined){ preview.innerHTML=resp.data.html; } }).catch(()=>{}); }; form.addEventListener("change",function(e){ if(!e.target) return; if(e.target.name==="simulation_lock_start_point"||e.target.name==="simulation_lock_end_point"||e.target.name==="simulation_overtime_dates[]") schedule(); else schedule(); }); form.addEventListener("input",function(e){ if(!e.target) return; if(e.target.name==="simulation_overtime_extra_hours"||e.target.name.indexOf("simulation_overtime_minutes[")===0||e.target.name==="simulation_work_hours") schedule(); }); syncReadonly(); syncExtra();})();</script>';
            echo '<script>(function(){const mapsProvider=' . wp_json_encode($mapsProvider) . '; const mapsKey=' . wp_json_encode($gmKey) . '; function loadGoogle(cb){ if(mapsProvider!="google"||!mapsKey){ cb(false); return; } if(window.google&&google.maps&&google.maps.places){ cb(true); return; } const existing=document.querySelector("script[data-routespro-google=\\"1\\"]"); if(existing){ existing.addEventListener("load",function(){ cb(true); },{once:true}); return; } const s=document.createElement("script"); s.src="https://maps.googleapis.com/maps/api/js?key="+encodeURIComponent(mapsKey)+"&libraries=places"; s.async=true; s.defer=true; s.dataset.routesproGoogle="1"; s.onload=function(){ cb(true); }; s.onerror=function(){ cb(false); }; document.head.appendChild(s); } function refs(input){ return { lat: document.querySelector(input.dataset.lat||""), lng: document.querySelector(input.dataset.lng||"") }; } function trigger(input){ if(input) input.dispatchEvent(new Event("change",{bubbles:true})); } function applyPlace(input, place){ if(!input||!place) return; const r=refs(input); input.value=place.formatted_address||input.value||""; if(place.geometry&&r.lat&&r.lng){ r.lat.value=String(place.geometry.location.lat()); r.lng.value=String(place.geometry.location.lng()); trigger(r.lat); trigger(r.lng); } trigger(input); } function bindOne(input){ if(!input||input.dataset.routesproAcBound==="1"||!(window.google&&google.maps&&google.maps.places&&google.maps.places.Autocomplete)) return; input.dataset.routesproAcBound="1"; const ac=new google.maps.places.Autocomplete(input,{componentRestrictions:{country:["pt","es"]},fields:["formatted_address","geometry","name"]}); ac.addListener("place_changed",function(){ applyPlace(input, ac.getPlace()); }); input.addEventListener("input",function(){ const r=refs(input); if(r.lat) r.lat.value=""; if(r.lng) r.lng.value=""; }); input.addEventListener("blur",function(){ const r=refs(input); if(!input.value.trim()||!r.lat||!r.lng||r.lat.value||r.lng.value||!(window.google&&google.maps&&google.maps.Geocoder)) return; const geocoder=new google.maps.Geocoder(); geocoder.geocode({address:input.value.trim()}, function(results,status){ if(status==="OK"&&results&&results[0]) applyPlace(input, results[0]); }); }); } loadGoogle(function(ok){ if(!ok) return; document.querySelectorAll(".routespro-simulation-address").forEach(bindOne); }); })();</script>';
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
        wp_send_json_success(['html' => self::render_plan_preview_html($plan, $plan_scope, $owner_user_id, $users, $holiday_country, $simulation_options)]);error_log('PLAN scope=' . $plan_scope .
    ' days=' . count((array)($plan['days'] ?? [])) .
    ' preview_days=' . count((array)($plan['preview_days'] ?? [])) .
    ' unassigned=' . count((array)($plan['unassigned'] ?? [])) .
    ' total_stops=' . (int)($plan['summary']['stops'] ?? 0)
);
        
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

    $broken = (array)($suggested['broken_periodicity'] ?? []);
    if ($broken) {
        $count = count($broken);
        $sample = array_slice($broken, 0, 10);
        $lines = [];
        foreach ($sample as $b) {
            $lines[] =
                (string)($b['name'] ?? '') .
                ((string)($b['city'] ?? '') ? ' · ' . (string)($b['city'] ?? '') : '') .
                ((string)($b['to_date'] ?? '') ? ' · ' . date_i18n('d/m/Y', strtotime((string)$b['to_date'])) : '') .
                ((string)($b['from_week'] ?? '') && (string)($b['to_week'] ?? '') ? ' · movida de ' . (string)($b['from_week'] ?? '') . ' para ' . (string)($b['to_week'] ?? '') : '');
        }
        echo '<div style="margin:10px 0 12px;padding:14px;border:1px solid #a7f3d0;border-radius:16px;background:#ecfdf5;color:#065f46">';
        echo '<strong>Periodicidade ajustada para equilibrar carga:</strong> ' . intval($count) . ' visita(s) foram adiantadas para evitar dias vazios / desequilíbrio (mantendo o limite de tempo).';
        echo '<div style="margin-top:8px;font-size:12px;color:#047857">Exemplos: ' . esc_html(implode(' | ', $lines)) . ($count > count($sample) ? ' …' : '') . '</div>';
        echo '</div>';
    }

    $daysToRender = [];
    if (!empty($suggested['preview_days']) && is_array($suggested['preview_days'])) {
        $daysToRender = $suggested['preview_days'];
    } elseif (!empty($suggested['days']) && is_array($suggested['days'])) {
        $daysToRender = $suggested['days'];
    }

    if (!$daysToRender) {
        echo '<p>Sem dados suficientes para gerar a sugestão. Garante PDVs ativos, com coordenadas válidas e, se aplicável, atribuídos ao owner selecionado.</p>';
        return (string) ob_get_clean();
    }

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">';
    foreach ($daysToRender as $day) {
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
            $adjusted = !empty($item['periodicity_broken']);
            echo '<li><strong>'.esc_html($item['name']).'</strong>' . ($adjusted ? ' <span style="display:inline-block;margin-left:6px;font-size:11px;color:#065f46;background:#d1fae5;border:1px solid #6ee7b7;border-radius:999px;padding:2px 8px">ajustada</span>' : '') . '<br><span style="color:#64748b">'.esc_html($item['city']).' · '.esc_html($item['visit_duration_min']).' min · '.esc_html($item['visit_frequency']).($item['copy_index']>1 ? ' #'.intval($item['copy_index']) : '').'</span></li>';
        }
        echo '</ol></div>';
    }
    echo '</div>';

    echo '<div style="margin-top:14px;padding:14px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#334155">Resumo '.($plan_scope === 'monthly' ? 'mensal' : 'semanal').': '.intval($suggested['summary']['stops'] ?? 0).' visitas planeadas, '.esc_html($suggested['summary']['travel_human'] ?? '0m').' de viagem estimada, '.esc_html($suggested['summary']['visit_human'] ?? '0m').' em loja, '.esc_html($suggested['summary']['lunch_human'] ?? '0m').' de almoço, trabalho total '.esc_html($suggested['summary']['work_human'] ?? '0m').', dia total '.esc_html($suggested['summary']['total_human'] ?? '0m').(!empty($suggested['summary']['overtime_human']) && $suggested['summary']['overtime_human'] !== '0m' ? ', fora do horário '.esc_html($suggested['summary']['overtime_human']) : '').'.</div>';

    if (!empty($suggested['reinforcement']['recommended'])) {
        echo '<div style="margin-top:14px;padding:16px;border:1px solid #f59e0b;border-radius:16px;background:#fffbeb;color:#78350f"><strong>Reforço operacional sugerido.</strong> O plano ultrapassa o objetivo normal de 8h de trabalho por dia. O sistema só admite até <strong>' . esc_html((string)($suggested['reinforcement']['max_overtime_per_day_human'] ?? '2h 30m')) . '</strong> adicionais por dia, para um máximo operacional de <strong>10h 30m de trabalho</strong>. Horas extra totais estimadas: <strong>' . esc_html((string)($suggested['reinforcement']['overtime_human'] ?? '0m')) . '</strong>'.(!empty($suggested['reinforcement']['zone']) ? ' · Zona prioritária para dividir a rota: <strong>' . esc_html((string)$suggested['reinforcement']['zone']) . '</strong>' : '').'. Carga sugerida para um 2.º membro: <strong>' . esc_html((string)($suggested['reinforcement']['second_member_share_human'] ?? '0m')) . '</strong>'.(!empty($suggested['reinforcement']['unassigned_count']) ? ' Existem <strong>' . intval($suggested['reinforcement']['unassigned_count']) . '</strong> visitas que já não cabem dentro do período sem reforço.' : '').'</div>';
    }
    if (!empty($suggested['unassigned'])) {
        echo '<div style="margin-top:12px;padding:14px;border:1px solid #fecaca;border-radius:16px;background:#fef2f2;color:#991b1b"><strong>Visitas por encaixar no período:</strong> ' . intval(count((array)$suggested['unassigned'])) . '. Para manter a periodicidade sem ultrapassar 2h30m de horas extra por dia, estas visitas devem ser absorvidas por reforço de equipa ou redistribuição operacional.</div>';
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
            'work_minutes' => max(60, min(480, $work_minutes ?: 480)),
            'lunch_minutes' => max(0, min(180, (int)($raw['lunch_minutes'] ?? 60))),
            'allow_overtime' => array_key_exists('allow_overtime', $raw) ? !empty($raw['allow_overtime']) : false,
            'overtime_extra_minutes' => max(0, min(150, (int)($raw['overtime_extra_minutes'] ?? 0))),
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
    $rawOptions = is_array($options) ? $options : [];
    $norm = self::normalize_plan_options($rawOptions);

    // Preserva chaves extra (ex: min_open_days) e garante defaults normalizados
    $options = array_merge($rawOptions, $norm);

    return $scope === 'monthly'
        ? self::build_month_plan($linked, $base_date, $holiday_country, $options)
        : self::build_week_plan($linked, $base_date, $holiday_country, $options);
}

private static function build_month_plan(array $linked, string $base_date, string $holiday_country = 'pt', array $options = []): array {
    $rawOptions = is_array($options) ? $options : [];
    $norm = self::normalize_plan_options($rawOptions);
    $options = array_merge($rawOptions, $norm);

    $calendar = self::get_month_business_calendar($base_date, $holiday_country);
    $weekBuckets = (array)($calendar['weeks'] ?? []);
    $excludedDays = (array)($calendar['excluded_days'] ?? []);
    $allWeekdays = (array)($calendar['all_weekdays'] ?? []);

    if (!$weekBuckets) {
        return [
            'days' => [],
            'preview_days' => [],
            'summary' => [],
            'scope' => 'monthly',
            'period_label' => (string)($calendar['period_label'] ?? ''),
            'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })),
            'excluded_days' => $excludedDays,
            'options' => $options,
            'unassigned' => [],
            'reinforcement' => ['recommended' => false],
            'broken_periodicity' => [],
        ];
    }
    

    $tasksByWeek = self::build_tasks_by_week_for_calendar($linked, $weekBuckets);
    if (!$tasksByWeek) {
        return [
            'days' => [],
            'preview_days' => [],
            'summary' => [],
            'scope' => 'monthly',
            'period_label' => (string)($calendar['period_label'] ?? ''),
            'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })),
            'excluded_days' => $excludedDays,
            'options' => $options,
            'unassigned' => [],
            'reinforcement' => ['recommended' => false],
            'broken_periodicity' => [],
        ];
    }

    // ----- helpers -----
    $dayHasStore = function(array $day, array $task): bool {
        $id = (int)($task['id'] ?? 0);
        if ($id <= 0) return false;
        foreach ((array)($day['items'] ?? []) as $it) {
            if ((int)($it['id'] ?? 0) === $id) return true;
        }
        return false;
    };

    $dayRefPoint = function(array $day, array $weekOptions): ?array {
        $items = (array)($day['items'] ?? []);
        if ($items) {
            $last = end($items);
            if (is_array($last) && is_numeric($last['lat'] ?? null) && is_numeric($last['lng'] ?? null)) return $last;
        }
        $sp = is_array($weekOptions['start_point'] ?? null) ? $weekOptions['start_point'] : [];
        if (is_numeric($sp['lat'] ?? null) && is_numeric($sp['lng'] ?? null)) return $sp;
        return null;
    };

    // Selecionar o "melhor dia" APENAS por carga/folga (primeiro 0 lojas, depois menos stops, depois menor work_min)
    $pickBestDayIndex = function(array $weekPreview): ?int {
        if (!$weekPreview) return null;
        $bestIdx = null;
        $bestTuple = null; // [stops, work_min]
        foreach ($weekPreview as $idx => $d) {
            $stops = (int)($d['stops'] ?? 0);
            $work = (int)($d['work_min'] ?? 0);
            $tuple = [$stops, $work];
            if ($bestTuple === null || $tuple < $bestTuple) {
                $bestTuple = $tuple;
                $bestIdx = $idx;
            }
        }
        return $bestIdx;
    };

    // Selecionar task para um dia: prioridade (alto), monthly (prefer), depois zona/distância (só aqui entra distância)
    $pickBestTaskIndexForDay = function(array $day, array $weekOptions, string $weekKey, array $pool, bool $allowPullBack = false) use ($dayHasStore, $dayRefPoint): ?int {
        if (!$pool) return null;

        $ref = $dayRefPoint($day, $weekOptions);
        $bestI = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($pool as $i => $task) {
            $fromWeek = (string)($task['target_week_key'] ?? '');

            // Por defeito só adiantar (não puxar para trás). Se não houver alternativas, podemos permitir pull-back.
            if (!$allowPullBack && $fromWeek !== '' && $fromWeek < $weekKey) continue;

            // não duplicar loja no mesmo dia
            if ($dayHasStore($day, $task)) continue;

            // score: menor é melhor
            $score = 0.0;

            // prioridade domina
            $score -= ((int)($task['priority'] ?? 0)) * 100.0;

            // monthly preferido
            if (($task['visit_frequency'] ?? 'weekly') === 'monthly') $score -= 40.0;

            // zona (cidade/distrito)
            $last = null;
            $items = (array)($day['items'] ?? []);
            if ($items) $last = end($items);
            $sameZone = self::same_zone_score(is_array($last) ? $last : null, $task);
            $score += $sameZone ? -50.0 : 10.0;

            // distância (apenas desempate dentro do dia)
            if ($ref && is_numeric($task['lat'] ?? null) && is_numeric($task['lng'] ?? null)) {
                $km = self::haversine_km((float)$ref['lat'], (float)$ref['lng'], (float)$task['lat'], (float)$task['lng']);
                $score += ($km * 2.8);
            }

            // pequena penalização por mover entre semanas
            if ($fromWeek !== '' && $fromWeek !== $weekKey) $score += 6.0;

            if ($score < $bestScore) { $bestScore = $score; $bestI = $i; }
        }

        return $bestI;
    };

    // Pool global (todas as tasks do mês)
    $globalPool = [];
    foreach ($tasksByWeek as $wk => $tasks) {
        foreach ((array)$tasks as $t) $globalPool[] = $t;
    }

    // Ordenação base do pool (para estabilidade)
    usort($globalPool, function($a, $b){
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;
        $fa = (($a['visit_frequency'] ?? 'weekly') === 'monthly') ? 0 : 1;
        $fb = (($b['visit_frequency'] ?? 'weekly') === 'monthly') ? 0 : 1;
        if ($fa !== $fb) return $fa <=> $fb;
        return strcmp(strtolower((string)($a['name'] ?? '')), strtolower((string)($b['name'] ?? '')));
    });

    // Helper para remover tasks "devidas" (não duplicar)
    $removeMatchingFromPool = function(array $task) use (&$globalPool): void {
        foreach ($globalPool as $i => $t) {
            if (
                (int)($t['id'] ?? 0) === (int)($task['id'] ?? 0) &&
                (int)($t['copy_index'] ?? 0) === (int)($task['copy_index'] ?? 0) &&
                (string)($t['visit_frequency'] ?? '') === (string)($task['visit_frequency'] ?? '') &&
                (string)($t['target_week_key'] ?? '') === (string)($task['target_week_key'] ?? '')
            ) {
                unset($globalPool[$i]);
                $globalPool = array_values($globalPool);
                return;
            }
        }
    };

    $days = [];
    $previewDays = [];
    $allUnassigned = [];
    $broken = [];

    foreach ($weekBuckets as $weekKey => $bucket) {
        $bucketDates = array_values((array)($bucket['dates'] ?? []));
        $bucketLabel = (string)($bucket['label'] ?? 'Semana');

        $weekTasks = (array)($tasksByWeek[$weekKey] ?? []);
        foreach ($weekTasks as $t) $removeMatchingFromPool($t);

        $weekOptions = $options;
        if ($bucketDates) {
            $weekOptions['min_open_days'] = max((int)($weekOptions['min_open_days'] ?? 0), count($bucketDates));
        }

        // Planeamento base
        $weekOptions = $options;
$weekOptions['min_open_days'] = max((int)($weekOptions['min_open_days'] ?? 0), count($bucketDates));

$planned = self::plan_tasks_into_dates(
    (array)($tasksByWeek[$weekKey] ?? []),
    $bucketDates,
    (string)($bucket['label'] ?? 'Semana'),
    $weekOptions
);
        $weekDays = (array)($planned['days'] ?? []);
        $weekPreview = (array) self::build_preview_days($weekDays, $bucketDates, $bucketLabel);
        foreach ($weekPreview as &$d) $d['week_key'] = $weekKey;
        unset($d);

        $maxStops = (int)($weekOptions['max_stops_per_day'] ?? 12);

        // Preencher até não existirem dias 0 (e depois equilibrar um pouco)
        $safety = 0;
        while ($safety < 500) {
            $safety++;

            if (!$globalPool) break;

            // escolher o dia mais vazio/folgado (sem olhar distância)
            $dayIndex = $pickBestDayIndex($weekPreview);
            if ($dayIndex === null) break;

            $day = $weekPreview[$dayIndex];

            // se já está cheio, parar
            if ((int)($day['stops'] ?? 0) >= $maxStops) {
                // se o melhor dia está cheio, então todos estão no limite -> parar
                break;
            }

            // condição de paragem: se não há dias vazios e já está "equilibrado o suficiente"
            $hasEmpty = false;
            $minStops = PHP_INT_MAX;
            $maxStopsSeen = 0;
            foreach ($weekPreview as $d) {
                $st = (int)($d['stops'] ?? 0);
                if ($st === 0) $hasEmpty = true;
                $minStops = min($minStops, $st);
                $maxStopsSeen = max($maxStopsSeen, $st);
            }

            // Se não há vazios e diferença <= 1, não mexer mais (evita over-fill)
            if (!$hasEmpty && ($maxStopsSeen - $minStops) <= 1) break;

            // escolher task para ESTE dia (distância só aqui)
            $bestI = $pickBestTaskIndexForDay($day, $weekOptions, (string)$weekKey, $globalPool, false);

            // Se não encontrou (por ex. só há tasks "para trás"), permitir pull-back como fallback
            if ($bestI === null) {
                $bestI = $pickBestTaskIndexForDay($day, $weekOptions, (string)$weekKey, $globalPool, true);
            }

            if ($bestI === null) break;

            $picked = $globalPool[$bestI];
            unset($globalPool[$bestI]);
            $globalPool = array_values($globalPool);

            // Marcar quebra de periodicidade
            $picked['periodicity_broken'] = true;
            $picked['moved_from_week'] = (string)($picked['target_week_key'] ?? '');
            $picked['moved_to_week'] = (string)$weekKey;
            $picked['target_week_key_original'] = (string)($picked['target_week_key'] ?? '');
            $picked['target_week_key'] = (string)$weekKey;

            $broken[] = [
                'name' => (string)($picked['name'] ?? ''),
                'city' => (string)($picked['city'] ?? ''),
                'visit_frequency' => (string)($picked['visit_frequency'] ?? ''),
                'copy_index' => (int)($picked['copy_index'] ?? 1),
                'from_week' => (string)($picked['moved_from_week'] ?? ''),
                'to_week' => (string)($picked['moved_to_week'] ?? ''),
                'to_date' => (string)($day['date'] ?? ''),
            ];

            // Inserir (garantia anti-duplicado no mesmo dia)
            if (empty($weekPreview[$dayIndex]['items']) || !is_array($weekPreview[$dayIndex]['items'])) {
                $weekPreview[$dayIndex]['items'] = [];
            }

            if (!$dayHasStore($weekPreview[$dayIndex], $picked)) {
                $weekPreview[$dayIndex]['items'][] = $picked;
                $weekPreview[$dayIndex]['stops'] = (int)($weekPreview[$dayIndex]['stops'] ?? 0) + 1;
                $weekPreview[$dayIndex]['visit_min'] = (int)($weekPreview[$dayIndex]['visit_min'] ?? 0) + (int)($picked['visit_duration_min'] ?? 45);

                // Recalcular métricas do dia (travel_min fica aproximado)
                self::finalize_planned_day($weekPreview[$dayIndex], (int)$weekOptions['work_minutes'], (int)$weekOptions['lunch_minutes']);
            }
        }
        $weekOptions['_debug_scope'] = 'monthly';

        // Replanear semana inteira para obter uma rota “coerente” (sequência e travel)
        $finalTasks = [];
        foreach ($weekPreview as $d) {
            foreach ((array)($d['items'] ?? []) as $it) $finalTasks[] = $it;
        }

        $finalPlanned = self::plan_tasks_into_dates($finalTasks, $bucketDates, $bucketLabel, $weekOptions);

        foreach ((array)($finalPlanned['days'] ?? []) as $day) $days[] = $day;
        foreach ((array) self::build_preview_days((array)($finalPlanned['days'] ?? []), $bucketDates, $bucketLabel) as $day) $previewDays[] = $day;
        foreach ((array)($finalPlanned['unassigned'] ?? []) as $left) $allUnassigned[] = $left;
    }

    usort($days, function($a, $b){ return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')); });
    usort($previewDays, function($a, $b){ return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')); });

    $summary = self::summarize_days($days, $allUnassigned, count($allWeekdays));
    $reinforcement = self::build_reinforcement_summary($days, $allUnassigned, (int)$options['work_minutes'], count($allWeekdays));

    return [
        'days' => $days,
        'preview_days' => $previewDays,
        'summary' => $summary,
        'scope' => 'monthly',
        'period_label' => (string)($calendar['period_label'] ?? ''),
        'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })),
        'excluded_days' => $excludedDays,
        'options' => $options,
        'unassigned' => $allUnassigned,
        'reinforcement' => $reinforcement,
        'broken_periodicity' => $broken,
    ];
}

private static function build_week_plan(array $linked, string $base_date = '', string $holiday_country = 'pt', array $options = []): array {
    // Preservar opções extra (ex.: min_open_days) — normalize_plan_options() descarta chaves desconhecidas
    $rawOptions = is_array($options) ? $options : [];
    $normOptions = self::normalize_plan_options($rawOptions);
    $options = array_merge($rawOptions, $normOptions);
    

    // 1) Determinar semana (2ª a 6ª) que contém o base_date
    $baseTs = strtotime($base_date ?: date('Y-m-d'));
    if (!$baseTs) $baseTs = current_time('timestamp');

    // Garante que é mesmo "a semana do base_date" e não +1 semana por causa de parsing
    $weekStartTs = strtotime('monday this week', $baseTs);
    if (!$weekStartTs) $weekStartTs = $baseTs;
    $weekEndTs = strtotime('+4 day', $weekStartTs);

    // 2) Feriados só dentro do intervalo desta semana (não procurar 21 dias nem compensar)
    $holidayMap = self::get_holiday_map($holiday_country, [(int)date('Y', $weekStartTs), (int)date('Y', $weekEndTs)]);

    $dates = [];
    $excludedDays = [];
    for ($ts = $weekStartTs; $ts <= $weekEndTs; $ts = strtotime('+1 day', $ts)) {
        $date = date('Y-m-d', $ts);
        $dow = (int) date('N', $ts);

        if ($dow >= 6) { // redundante (já só percorremos 2ª-6ª), mas mantém consistência
            $excludedDays[$date] = 'Fim de semana';
            continue;
        }
        if (isset($holidayMap[$date])) {
            $excludedDays[$date] = $holidayMap[$date];
            continue;
        }
        $dates[] = $date;
    }

    if (!$dates) {
        return [
            'days' => [],
            'preview_days' => [],
            'summary' => [],
            'scope' => 'weekly',
            'excluded_holidays' => array_keys(array_filter($excludedDays, fn($reason) => $reason !== 'Fim de semana')),
            'excluded_days' => $excludedDays,
            'options' => $options,
            'unassigned' => [],
            'reinforcement' => ['recommended' => false],
        ];
    }

    // Forçar que o planner "abre" (pelo menos) todos os dias úteis desta semana.
    // (Se tiveres um spread real dentro do plan_tasks_into_dates, isto ajuda a não colapsar tudo no dia 1.)
    $options['min_open_days'] = max((int)($options['min_open_days'] ?? 0), count($dates));

    // NOVO: distância mínima entre visitas à mesma loja (gap em dias)
    // Gap = 2 => nunca visita a mesma loja em dias seguidos.
    $options['min_days_between_same_store'] = 2;

    // 3) Construir tasks (visitas) a partir dos linked
    $tasks = [];
    foreach ($linked as $row) {
        if (empty($row['campaign_active']) || ($row['campaign_status'] ?? 'active') !== 'active') continue;

        $lat = isset($row['lat']) ? (float)$row['lat'] : null;
        $lng = isset($row['lng']) ? (float)$row['lng'] : null;
        if (!is_finite($lat) || !is_finite($lng)) continue;

        $freq = ($row['visit_frequency'] ?: 'weekly');
        $count = max(1, (int)($row['frequency_count'] ?? 1));

        // Semanal: até 7 visitas nessa semana
        // Mensal: entra como 1 visita na semana (a distribuição mensal acontece no build_month_plan)
        $visits = $freq === 'weekly' ? min(7, $count) : 1;

        for ($i = 1; $i <= $visits; $i++) {
            $copy = $row;
            $copy['copy_index'] = $i;
            $copy['visit_frequency'] = $freq;
            $copy['visit_duration_min'] = max(0, min(360, (int)($row['visit_duration_min'] ?? 45)));
            $tasks[] = $copy;
        }
    }

    if (!$tasks) {
        return [
            'days' => [],
            'preview_days' => [],
            'summary' => [],
            'scope' => 'weekly',
            'excluded_holidays' => array_keys(array_filter($excludedDays, fn($reason) => $reason !== 'Fim de semana')),
            'excluded_days' => $excludedDays,
            'options' => $options,
            'unassigned' => [],
            'reinforcement' => ['recommended' => false],
        ];
    }
$options['_debug_scope'] = 'weekly';
    // 4) Planear tasks para os dias úteis disponíveis (2ª-6ª sem feriados)
    $planned = self::plan_tasks_into_dates($tasks, $dates, 'Dia', $options);

    $days = (array)($planned['days'] ?? []);
    $unassigned = (array)($planned['unassigned'] ?? []);
    $summary = self::summarize_days($days, $unassigned, count($dates));
    $reinforcement = (array)($planned['reinforcement'] ?? []);

    // Preview_days: garante que todos os dias do período aparecem na UI, mesmo sem lojas
    $previewDays = (array) self::build_preview_days($days, $dates, 'Dia');

    return [
        'days' => $days,
        'preview_days' => $previewDays,
        'summary' => $summary,
        'scope' => 'weekly',
        'period_label' => date_i18n('d/m/Y', $weekStartTs) . ' - ' . date_i18n('d/m/Y', $weekEndTs),
        'week_start' => date('Y-m-d', $weekStartTs),
        'week_end' => date('Y-m-d', $weekEndTs),
        'excluded_holidays' => array_keys(array_filter($excludedDays, fn($reason) => $reason !== 'Fim de semana')),
        'excluded_days' => $excludedDays,
        'options' => $options,
        'unassigned' => $unassigned,
        'reinforcement' => $reinforcement,
    ];
}

    private static function get_month_business_calendar(string $base_date, string $holiday_country = 'pt'): array {
        $baseTs = strtotime($base_date ?: date('Y-m-d'));
        if (!$baseTs) $baseTs = current_time('timestamp');
        $monthStart = date('Y-m-01', $baseTs);
        $monthEnd = date('Y-m-t', $baseTs);
        $holidayMap = self::get_holiday_map($holiday_country, [(int)date('Y', strtotime($monthStart)), (int)date('Y', strtotime($monthEnd))]);
        $weeks = [];
        $excludedDays = [];
        $allWeekdays = [];
        $selectedWeekKey = date('Y-m-d', strtotime('monday this week', $baseTs));

        for ($ts = strtotime($monthStart); $ts <= strtotime($monthEnd); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $dow = (int) date('N', $ts);
            if ($dow >= 6) { $excludedDays[$date] = 'Fim de semana'; continue; }
            if (isset($holidayMap[$date])) { $excludedDays[$date] = $holidayMap[$date]; continue; }
            $weekKey = date('Y-m-d', strtotime('monday this week', $ts));
            if (!isset($weeks[$weekKey])) {
                $weeks[$weekKey] = [
                    'key' => $weekKey,
                    'index' => count($weeks) + 1,
                    'label' => 'Semana ' . (count($weeks) + 1),
                    'dates' => [],
                    // NOVO: week_start/week_end por bucket (só dias úteis do mês)
                    'week_start' => $weekKey,
                    'week_end' => $weekKey,
                ];
            }
            $weeks[$weekKey]['dates'][] = $date;
            // Atualiza week_end baseado no último dia útil adicionado
            $weeks[$weekKey]['week_end'] = $date;

            $allWeekdays[] = $date;
        }

        return [
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
            'period_label' => date_i18n('F Y', strtotime($monthStart)),
            'weeks' => $weeks,
            'all_weekdays' => $allWeekdays,
            'excluded_days' => $excludedDays,
            'selected_week_key' => $selectedWeekKey,
        ];
    }

 private static function build_tasks_by_week_for_calendar(array $linked, array $weekBuckets): array {
    if (!$linked || !$weekBuckets) return [];

    $weekKeys = array_keys($weekBuckets);
    $weekLoad = array_fill_keys($weekKeys, 0);
    $tasksByWeek = [];

    usort($linked, function($a, $b){
        $ca = max(1, (int)($a['frequency_count'] ?? 1));
        $cb = max(1, (int)($b['frequency_count'] ?? 1));
        if ($ca !== $cb) return $cb <=> $ca;
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;
        $ka = strtolower(trim((string)($a['district'] ?? '') . '|' . (string)($a['city'] ?? '') . '|' . (string)($a['name'] ?? '')));
        $kb = strtolower(trim((string)($b['district'] ?? '') . '|' . (string)($b['city'] ?? '') . '|' . (string)($b['name'] ?? '')));
        return $ka <=> $kb;
    });

    foreach ($linked as $row) {
        if (empty($row['campaign_active']) || ($row['campaign_status'] ?? 'active') !== 'active') continue;

        $lat = isset($row['lat']) ? (float)$row['lat'] : null;
        $lng = isset($row['lng']) ? (float)$row['lng'] : null;
        if (!is_finite($lat) || !is_finite($lng)) continue;

        $count = max(1, (int)($row['frequency_count'] ?? 1));
        $seedKey = (string)((int)($row['id'] ?? 0)) . '|' . (string)($row['name'] ?? '');

        $assignedWeekKeys = self::allocate_monthly_week_assignments($count, $weekBuckets, $weekLoad, $seedKey);

        foreach ($assignedWeekKeys as $i => $weekKey) {
            $copy = $row;
            $copy['copy_index'] = $i + 1;
            $copy['visit_frequency'] = 'monthly';
            $copy['visit_duration_min'] = max(0, min(360, (int)($row['visit_duration_min'] ?? 45)));
            $copy['target_week_key'] = $weekKey;
            $copy['uid'] = (string)((int)($row['id'] ?? 0)) . '|monthly|' . (string)($i + 1) . '|' . (string)$weekKey;

            $tasksByWeek[$weekKey][] = $copy;
            $weekLoad[$weekKey] = ($weekLoad[$weekKey] ?? 0) + 1;
        }
    }

    return $tasksByWeek;
}

private static function allocate_monthly_week_assignments(int $count, array $weekBuckets, array $weekLoad = [], string $seedKey = ''): array {
    $weekKeys = array_values(array_keys($weekBuckets));
    $weekCount = count($weekKeys);
    if ($weekCount <= 0) return [];

    $count = max(1, (int)$count);
    $assigned = [];

    if ($count === 1) {
        return [$weekKeys[0]];
    }

    if ($count === 2) {
        if ($weekCount >= 4) return [$weekKeys[0], $weekKeys[2]];
        if ($weekCount === 3) return [$weekKeys[0], $weekKeys[2]];
        return [$weekKeys[0], $weekKeys[1]];
    }

    if ($count === 3) {
        if ($weekCount >= 5) return [$weekKeys[0], $weekKeys[2], $weekKeys[4]];
        if ($weekCount === 4) return [$weekKeys[0], $weekKeys[1], $weekKeys[3]];
        return array_slice($weekKeys, 0, min(3, $weekCount));
    }

    if ($count <= $weekCount) {
        return array_slice($weekKeys, 0, $count);
    }

    $assigned = $weekKeys;
    $extra = $count - $weekCount;

    $priorityOrder = [];
    foreach ($weekKeys as $idx => $wk) {
        $priorityOrder[] = ['wk' => $wk, 'idx' => $idx, 'load' => (int)($weekLoad[$wk] ?? 0), 'dates' => count((array)($weekBuckets[$wk]['dates'] ?? []))];
    }

    usort($priorityOrder, function($a, $b){
        if ($a['load'] !== $b['load']) return $a['load'] <=> $b['load'];
        if ($a['dates'] !== $b['dates']) return $b['dates'] <=> $a['dates'];
        return $a['idx'] <=> $b['idx'];
    });

    $cursor = 0;
    while ($extra > 0 && $priorityOrder) {
        $assigned[] = $priorityOrder[$cursor]['wk'];
        $cursor = ($cursor + 1) % count($priorityOrder);
        $extra--;
    }

    return $assigned;
}
private static function plan_tasks_into_dates(array $tasks, array $dates, string $label_prefix = 'Dia', array $options = []): array {
    if (!$tasks || !$dates) return ['days' => [], 'unassigned' => [], 'reinforcement' => []];

    $rawOptions = is_array($options) ? $options : [];
    $norm = self::normalize_plan_options($rawOptions);
    $options = array_merge($rawOptions, $norm);
    $dates = array_values($dates);

    $seen = [];
    $deduped = [];
    foreach ($tasks as $t) {
        $uid = (string)((int)($t['id'] ?? 0)) . '|' . (string)($t['visit_frequency'] ?? '') . '|' . (string)((int)($t['copy_index'] ?? 1)) . '|' . (string)($t['target_week_key'] ?? '');
        if ($uid === '0|||') continue;
        if (isset($seen[$uid])) continue;
        $seen[$uid] = true;
        $deduped[] = $t;
    }
    $tasks = $deduped;

    $visitsPerStore = [];
    foreach ($tasks as $t) {
        $sid = (int)($t['id'] ?? 0);
        if ($sid > 0) $visitsPerStore[$sid] = ($visitsPerStore[$sid] ?? 0) + 1;
    }

    $minOpenDaysRaw = isset($options['min_open_days']) ? (int)$options['min_open_days'] : 0;
    $minGapDays = max(0, min(14, (int)($options['min_days_between_same_store'] ?? 2)));
    $maxStops = (int)($options['max_stops_per_day'] ?? 12);
    $targetWorkMin = (int)($options['work_minutes'] ?? 480);
    $lunchMin = (int)($options['lunch_minutes'] ?? 60);
    $globalAllowOvertime = !empty($options['allow_overtime']);
    $defaultExtraMin = max(0, min(150, (int)($options['overtime_extra_minutes'] ?? 0)));
    $dailyOvertimeDates = array_flip((array)($options['daily_overtime_dates'] ?? []));
    $dailyOvertimeMinutes = (array)($options['daily_overtime_minutes'] ?? []);
    $startPoint = is_array($options['start_point'] ?? null) ? $options['start_point'] : [];
    $endPoint = is_array($options['end_point'] ?? null) ? $options['end_point'] : [];

    usort($tasks, function($a, $b){
        $fa = max(1, (int)($a['frequency_count'] ?? 1));
        $fb = max(1, (int)($b['frequency_count'] ?? 1));
        if ($fa !== $fb) return $fb <=> $fa;
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;
        $za = strtolower(trim((string)($a['district'] ?? '') . '|' . (string)($a['city'] ?? '') . '|' . (string)($a['name'] ?? '')));
        $zb = strtolower(trim((string)($b['district'] ?? '') . '|' . (string)($b['city'] ?? '') . '|' . (string)($b['name'] ?? '')));
        return $za <=> $zb;
    });

    $taskPool = array_values($tasks);
    $totalVisitMin = 0;
    foreach ($taskPool as $t) $totalVisitMin += (int)($t['visit_duration_min'] ?? 45);

    $estimatedTravelMin = (int) round(count($taskPool) * 18);
    $daysByStops = (int) ceil(count($taskPool) / max(1, $maxStops));
    $daysByTime = (int) ceil(($totalVisitMin + $estimatedTravelMin) / max(1, $targetWorkMin));
    $initialDays = max(1, min(count($dates), max($daysByStops, $daysByTime)));
    $minOpenDays = max(0, (int)$minOpenDaysRaw);
    if ($minOpenDays > 0) $initialDays = max($initialDays, min(count($dates), $minOpenDays));

    if (count($taskPool) >= count($dates)) {
        $initialDays = max($initialDays, count($dates));
    }

    $days = [];
    for ($i = 0; $i < $initialDays; $i++) {
        $date = (string)($dates[$i] ?? '');
        if ($date === '') continue;
        $hasDayOverride = isset($dailyOvertimeDates[$date]) || isset($dailyOvertimeMinutes[$date]);
        $dayExtraMin = isset($dailyOvertimeMinutes[$date]) ? min(150, (int)$dailyOvertimeMinutes[$date]) : ($hasDayOverride ? $defaultExtraMin : 0);
        $allowOvertime = ($hasDayOverride || $globalAllowOvertime) && $dayExtraMin > 0;
        if (!$allowOvertime) $dayExtraMin = 0;
        $hardWorkLimit = $targetWorkMin + $dayExtraMin;
        $days[] = [
            'label' => $label_prefix . ' · ' . ($i + 1),
            'date' => $date,
            'items' => [],
            'travel_min' => 0.0,
            'visit_min' => 0,
            'stops' => 0,
            'start_point' => $startPoint,
            'end_point' => $endPoint,
            'allow_overtime' => $allowOvertime,
            'extra_minutes' => $dayExtraMin,
            'hard_limit_minutes' => $hardWorkLimit,
        ];
    }

    $dateIndexMap = [];
    foreach ($dates as $idx => $d) $dateIndexMap[(string)$d] = (int)$idx;
    $lastVisitByStore = [];

    $dayHasStore = function(array $day, int $storeId): bool {
        foreach ((array)($day['items'] ?? []) as $it) {
            if ((int)($it['id'] ?? 0) === $storeId) return true;
        }
        return false;
    };

    $estimateAddedTravelMin = function(array $day): float {
        return empty($day['items']) ? 12.0 : 15.0;
    };

    $sequenceItemsForEstimation = function(array $items, array $startPoint): array {
        if (!$items) return [];
        $remaining = array_values($items);
        $seedIdx = 0;
        if (is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null)) {
            $best = PHP_FLOAT_MAX;
            foreach ($remaining as $i => $t) {
                if (!is_numeric($t['lat'] ?? null) || !is_numeric($t['lng'] ?? null)) continue;
                $km = self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$t['lat'], (float)$t['lng']);
                if ($km < $best) { $best = $km; $seedIdx = $i; }
            }
        }
        $route = [];
        $current = $remaining[$seedIdx];
        $route[] = $current;
        array_splice($remaining, $seedIdx, 1);
        while ($remaining) {
            $bestIdx = 0;
            $bestKm = PHP_FLOAT_MAX;
            foreach ($remaining as $i => $t) {
                if (is_numeric($current['lat'] ?? null) && is_numeric($current['lng'] ?? null) && is_numeric($t['lat'] ?? null) && is_numeric($t['lng'] ?? null)) {
                    $km = self::haversine_km((float)$current['lat'], (float)$current['lng'], (float)$t['lat'], (float)$t['lng']);
                } else {
                    $km = 9999.0;
                }
                if ($km < $bestKm) { $bestKm = $km; $bestIdx = $i; }
            }
            $current = $remaining[$bestIdx];
            $route[] = $current;
            array_splice($remaining, $bestIdx, 1);
        }
        return $route;
    };

    $estimateRouteTravelMin = function(array $items, array $startPoint, array $endPoint) use ($sequenceItemsForEstimation): float {
        if (!$items) return 0.0;
        $route = $sequenceItemsForEstimation($items, $startPoint);
        if (!$route) return 0.0;
        $travelMin = 0.0;
        $first = $route[0] ?? null;
        if (is_array($first) && is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null) && is_numeric($first['lat'] ?? null) && is_numeric($first['lng'] ?? null)) {
            $travelMin += self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$first['lat'], (float)$first['lng']) / 45 * 60;
        } else {
            $travelMin += 12.0;
        }
        for ($i = 1; $i < count($route); $i++) {
            $prev = $route[$i-1];
            $curr = $route[$i];
            if (is_numeric($prev['lat'] ?? null) && is_numeric($prev['lng'] ?? null) && is_numeric($curr['lat'] ?? null) && is_numeric($curr['lng'] ?? null)) {
                $travelMin += self::haversine_km((float)$prev['lat'], (float)$prev['lng'], (float)$curr['lat'], (float)$curr['lng']) / 45 * 60;
            } else {
                $travelMin += 15.0;
            }
        }
        $last = end($route);
        if (is_array($last) && is_numeric($endPoint['lat'] ?? null) && is_numeric($endPoint['lng'] ?? null) && is_numeric($last['lat'] ?? null) && is_numeric($last['lng'] ?? null)) {
            $travelMin += self::haversine_km((float)$last['lat'], (float)$last['lng'], (float)$endPoint['lat'], (float)$endPoint['lng']) / 45 * 60;
        }
        return $travelMin;
    };

    $estimateDayWorkWithTask = function(array $day, array $task) use ($estimateRouteTravelMin): float {
        $items = array_values((array)($day['items'] ?? []));
        $items[] = $task;
        $visitMin = 0;
        foreach ($items as $it) $visitMin += (int)($it['visit_duration_min'] ?? 45);
        $travelMin = $estimateRouteTravelMin($items, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
        return $visitMin + $travelMin;
    };

    $effectiveGapDays = function(array $task, int $baseGap) use ($visitsPerStore): int {
        $storeId = (int)($task['id'] ?? 0);
        if ($storeId <= 0 || (($visitsPerStore[$storeId] ?? 0) <= 1)) return 0;
        $freq = max(1, (int)($task['frequency_count'] ?? 1));
        if ($freq >= 4) return max(0, min(1, $baseGap));
        if ($freq === 3) return max(1, min(1, $baseGap));
        return $baseGap;
    };

    $canPlace = function(array $day, array $task, int $gapDays) use ($dayHasStore, $dateIndexMap, &$lastVisitByStore, $maxStops, $targetWorkMin, $estimateDayWorkWithTask): bool {
        $storeId = (int)($task['id'] ?? 0);
        if ($storeId <= 0) return false;
        if ((int)($day['stops'] ?? 0) >= $maxStops) return false;
        if ($dayHasStore($day, $storeId)) return false;

        if ($gapDays > 0) {
            $dIdx = $dateIndexMap[(string)($day['date'] ?? '')] ?? 0;
            if (isset($lastVisitByStore[$storeId]) && abs($dIdx - (int)$lastVisitByStore[$storeId]) < $gapDays) return false;
        }

        $hardLimit = (int)($day['hard_limit_minutes'] ?? $targetWorkMin);
        $nextWork = $estimateDayWorkWithTask($day, $task);
        if ($nextWork > $hardLimit) return false;
        return true;
    };

    $placeTask = function(int $dayIdx, array $task) use (&$days, $dateIndexMap, &$lastVisitByStore, $sequenceItemsForEstimation, $estimateRouteTravelMin): void {
        $storeId = (int)($task['id'] ?? 0);
        $days[$dayIdx]['items'][] = $task;
        $days[$dayIdx]['items'] = $sequenceItemsForEstimation((array)$days[$dayIdx]['items'], (array)($days[$dayIdx]['start_point'] ?? []));
        $days[$dayIdx]['stops'] = count((array)($days[$dayIdx]['items'] ?? []));
        $visitMin = 0;
        foreach ((array)($days[$dayIdx]['items'] ?? []) as $it) $visitMin += (int)($it['visit_duration_min'] ?? 45);
        $days[$dayIdx]['visit_min'] = $visitMin;
        $days[$dayIdx]['travel_min'] = $estimateRouteTravelMin((array)($days[$dayIdx]['items'] ?? []), (array)($days[$dayIdx]['start_point'] ?? []), (array)($days[$dayIdx]['end_point'] ?? []));
        $dIdx = $dateIndexMap[(string)($days[$dayIdx]['date'] ?? '')] ?? 0;
        if ($storeId > 0) $lastVisitByStore[$storeId] = (int)$dIdx;
    };

    $findBestDayIndex = function(array $task, bool $preferEmptyDays, bool $preferLightDays, int $gapDays) use (&$days, $canPlace, $targetWorkMin, $estimateDayWorkWithTask) {
        $bestDayIdx = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ($days as $di => $day) {
            if (!$canPlace($day, $task, $gapDays)) continue;
            $nextWork = $estimateDayWorkWithTask($day, $task);
            $currentStops = (int)($day['stops'] ?? 0);
            $currentWork = (float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0);
            $distanceToTarget = abs($targetWorkMin - $nextWork);
            $emptyBonus = ($preferEmptyDays && $currentStops === 0) ? -1000.0 : 0.0;
            $lightBonus = $preferLightDays ? ($currentStops * 120.0) + ($currentWork * 0.9) : 0.0;
            $freq = max(1, (int)($task['frequency_count'] ?? 1));
            $freqBonus = -1.0 * ($freq * 25.0);
            $overtimePenalty = max(0.0, $nextWork - $targetWorkMin) * 2.5;
            $score = $emptyBonus + $lightBonus + $distanceToTarget + $overtimePenalty + $freqBonus;
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestDayIdx = $di;
            }
        }
        return $bestDayIdx;
    };

    $remaining = $taskPool;

    // Fase 1: seed obrigatório para abrir e preencher dias vazios com maior periodicidade primeiro.
    foreach ($days as $di => $day) {
        if (!$remaining) break;
        $bestTaskIdx = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ($remaining as $ti => $task) {
            $gap = $effectiveGapDays($task, $minGapDays);
            if (!$canPlace($days[$di], $task, $gap)) continue;
            $freq = max(1, (int)($task['frequency_count'] ?? 1));
            $priority = (int)($task['priority'] ?? 0);
            $score = (-1000.0 * min($freq, 10)) + (-20.0 * $priority) + ($ti * 0.01);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestTaskIdx = $ti;
            }
        }
        if ($bestTaskIdx !== null) {
            $task = $remaining[$bestTaskIdx];
            $placeTask($di, $task);
            array_splice($remaining, $bestTaskIdx, 1);
        }
    }

    // Fase 2: preencher sempre o dia mais vazio, antes de otimizar dias já carregados.
    $newRemaining = [];
    foreach ($remaining as $task) {
        $gap = $effectiveGapDays($task, $minGapDays);
        $bestDayIdx = $findBestDayIndex($task, true, true, $gap);
        if ($bestDayIdx === null) {
            $newRemaining[] = $task;
            continue;
        }
        $placeTask($bestDayIdx, $task);
    }
    $remaining = $newRemaining;

    // Fase 3: nova tentativa com gap relaxado para periodicidade alta, mantendo foco nos dias mais leves.
    $unassigned = [];
    foreach ($remaining as $task) {
        $relaxedGap = max(0, $effectiveGapDays($task, $minGapDays) - 1);
        $bestDayIdx = $findBestDayIndex($task, false, true, $relaxedGap);
        if ($bestDayIdx === null) {
            $unassigned[] = $task;
            continue;
        }
        $placeTask($bestDayIdx, $task);
    }

    // Fase 4: pequeno rebalanceamento. Se houver dias vazios e dias carregados, mover 1 visita adequada.
    $hasEmptyDays = true;
    while ($hasEmptyDays) {
        $emptyIdx = null;
        $loadedIdx = null;
        foreach ($days as $di => $day) {
            if ((int)($day['stops'] ?? 0) === 0) { $emptyIdx = $di; break; }
        }
        if ($emptyIdx === null) break;
        foreach ($days as $di => $day) {
            if ($di === $emptyIdx) continue;
            if ((int)($day['stops'] ?? 0) >= 2) { $loadedIdx = $di; break; }
        }
        if ($loadedIdx === null) break;

        $moved = false;
        $items = array_values((array)($days[$loadedIdx]['items'] ?? []));
        foreach (array_reverse($items, true) as $itemIdx => $task) {
            $gap = max(0, $effectiveGapDays($task, $minGapDays) - 1);
            if (!$canPlace($days[$emptyIdx], $task, $gap)) continue;
            array_splice($days[$loadedIdx]['items'], $itemIdx, 1);
            $days[$loadedIdx]['items'] = $sequenceItemsForEstimation((array)$days[$loadedIdx]['items'], (array)($days[$loadedIdx]['start_point'] ?? []));
            $days[$loadedIdx]['stops'] = count((array)$days[$loadedIdx]['items']);
            $visitMinLoaded = 0;
            foreach ((array)$days[$loadedIdx]['items'] as $it) $visitMinLoaded += (int)($it['visit_duration_min'] ?? 45);
            $days[$loadedIdx]['visit_min'] = $visitMinLoaded;
            $days[$loadedIdx]['travel_min'] = $estimateRouteTravelMin((array)$days[$loadedIdx]['items'], (array)($days[$loadedIdx]['start_point'] ?? []), (array)($days[$loadedIdx]['end_point'] ?? []));
            $placeTask($emptyIdx, $task);
            $moved = true;
            break;
        }
        if (!$moved) break;
    }

    // Fase 5: se pontos de partida/chegada empurrarem um dia acima do limite, aliviar esse dia antes de fechar o plano.
    $safetyPass = 0;
    while ($safetyPass < 20) {
        $safetyPass++;
        $overIdx = null;
        foreach ($days as $di => $day) {
            $workMin = (float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0);
            $hardLimit = (int)($day['hard_limit_minutes'] ?? $targetWorkMin);
            if ($workMin > $hardLimit + 0.5 && (int)($day['stops'] ?? 0) > 0) {
                $overIdx = $di;
                break;
            }
        }
        if ($overIdx === null) break;

        $moved = false;
        $items = array_values((array)($days[$overIdx]['items'] ?? []));
        foreach (array_reverse($items, true) as $itemIdx => $task) {
            $gap = max(0, $effectiveGapDays($task, $minGapDays) - 1);
            $candidateIdx = $findBestDayIndex($task, true, true, $gap);
            if ($candidateIdx === null || $candidateIdx === $overIdx) continue;

            array_splice($days[$overIdx]['items'], $itemIdx, 1);
            $days[$overIdx]['items'] = $sequenceItemsForEstimation((array)$days[$overIdx]['items'], (array)($days[$overIdx]['start_point'] ?? []));
            $days[$overIdx]['stops'] = count((array)$days[$overIdx]['items']);
            $visitMinOver = 0;
            foreach ((array)$days[$overIdx]['items'] as $it) $visitMinOver += (int)($it['visit_duration_min'] ?? 45);
            $days[$overIdx]['visit_min'] = $visitMinOver;
            $days[$overIdx]['travel_min'] = $estimateRouteTravelMin((array)$days[$overIdx]['items'], (array)($days[$overIdx]['start_point'] ?? []), (array)($days[$overIdx]['end_point'] ?? []));

            $placeTask($candidateIdx, $task);
            $moved = true;
            break;
        }
        if (!$moved) break;
    }

    $sequenceDay = function(array $items, array $startPoint) : array {
        if (!$items) return [];
        $remaining = array_values($items);
        $seedIdx = 0;
        if (is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null)) {
            $best = PHP_FLOAT_MAX;
            foreach ($remaining as $i => $t) {
                if (!is_numeric($t['lat'] ?? null) || !is_numeric($t['lng'] ?? null)) continue;
                $km = self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$t['lat'], (float)$t['lng']);
                if ($km < $best) { $best = $km; $seedIdx = $i; }
            }
        }
        $route = [];
        $current = $remaining[$seedIdx];
        $route[] = $current;
        array_splice($remaining, $seedIdx, 1);
        while ($remaining) {
            $bestIdx = 0;
            $bestKm = PHP_FLOAT_MAX;
            foreach ($remaining as $i => $t) {
                if (is_numeric($current['lat'] ?? null) && is_numeric($current['lng'] ?? null) && is_numeric($t['lat'] ?? null) && is_numeric($t['lng'] ?? null)) {
                    $km = self::haversine_km((float)$current['lat'], (float)$current['lng'], (float)$t['lat'], (float)$t['lng']);
                } else {
                    $km = 9999.0;
                }
                if ($km < $bestKm) { $bestKm = $km; $bestIdx = $i; }
            }
            $current = $remaining[$bestIdx];
            $route[] = $current;
            array_splice($remaining, $bestIdx, 1);
        }
        return $route;
    };

    foreach ($days as &$day) {
        $day['items'] = $sequenceDay((array)($day['items'] ?? []), (array)($day['start_point'] ?? []));
        self::finalize_planned_day($day, $targetWorkMin, $lunchMin);
        unset($day['hard_limit_minutes']);
    }
    unset($day);

    $reinforcement = self::build_reinforcement_summary($days, $unassigned, $targetWorkMin, count($dates));
    return ['days' => $days, 'unassigned' => $unassigned, 'reinforcement' => $reinforcement];
}
    private static function finalize_planned_day(array &$day, int $targetWorkMin, int $lunchMin): void {
        $items = (array)($day['items'] ?? []);
        $day['return_min'] = 0.0;
        $workMin = (int) round((float)($day['travel_min'] ?? 0) + (float)($day['visit_min'] ?? 0));
        $overtimeMin = max(0, $workMin - $targetWorkMin);
        $lunchToApply = !empty($day['items']) ? $lunchMin : 0;
        $day['work_min'] = $workMin;
        $day['lunch_min'] = $lunchToApply;
        $day['overtime_min'] = $overtimeMin;
        $day['travel_human'] = self::human_minutes((int)round((float)($day['travel_min'] ?? 0)));
        $day['visit_human'] = self::human_minutes((int)($day['visit_min'] ?? 0));
        $day['work_human'] = self::human_minutes($workMin);
        $day['lunch_human'] = self::human_minutes($lunchToApply);
        $day['overtime_human'] = self::human_minutes($overtimeMin);
        $day['total_human'] = self::human_minutes($workMin + $lunchToApply);
        $dayExtraMin = !empty($day['allow_overtime']) ? max(0, min(150, (int)($day['extra_minutes'] ?? 0))) : 0;
        $day['can_add_store'] = ((int)($day['stops'] ?? 0) < 20) && ($workMin + 30 <= ($targetWorkMin + $dayExtraMin));
    }

    private static function build_preview_days(array $plannedDays, array $allDates, string $labelPrefix = 'Dia'): array {
        $preview = [];
        $byDate = [];
        foreach ($plannedDays as $day) {
            $date = (string)($day['date'] ?? '');
            if ($date !== '') $byDate[$date] = $day;
        }
        foreach (array_values($allDates) as $idx => $date) {
            $date = (string)$date;
            if ($date === '') continue;
            if (isset($byDate[$date])) {
                $preview[] = $byDate[$date];
                continue;
            }
            $preview[] = [
                'label' => $labelPrefix . ' · ' . ($idx + 1),
                'date' => $date,
                'items' => [],
                'travel_min' => 0,
                'visit_min' => 0,
                'stops' => 0,
                'allow_overtime' => false,
                'extra_minutes' => 0,
                'return_min' => 0,
                'work_min' => 0,
                'lunch_min' => 0,
                'overtime_min' => 0,
                'travel_human' => self::human_minutes(0),
                'visit_human' => self::human_minutes(0),
                'work_human' => self::human_minutes(0),
                'lunch_human' => self::human_minutes(0),
                'overtime_human' => self::human_minutes(0),
                'total_human' => self::human_minutes(0),
                'can_add_store' => true,
                'is_empty_slot' => true,
            ];
        }
        usort($preview, function($a, $b){ return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')); });
        return $preview;
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
        $peakDay = null;
        $availableOvertimeMinutes = 0;
        $daysOverMax = 0;
        $overloadedDays = 0;
        foreach ($days as $day) {
            if ($peakDay === null || (int)($day['overtime_min'] ?? 0) > (int)($peakDay['overtime_min'] ?? 0)) {
                $peakDay = $day;
            }
            $allowedExtra = !empty($day['allow_overtime']) ? max(0, min(150, (int)($day['extra_minutes'] ?? 0))) : 0;
            $availableOvertimeMinutes += $allowedExtra;
            if ((int)($day['overtime_min'] ?? 0) > 0) $overloadedDays++;
            if ((int)($day['work_min'] ?? 0) > ($targetWorkMin + $allowedExtra)) $daysOverMax++;
        }
        $zoneSource = $unassigned;
        if (!$zoneSource && is_array($peakDay)) $zoneSource = (array)($peakDay['items'] ?? []);
        $zone = self::pick_reinforcement_zone($zoneSource);
        $requiredExtraMinutes = 0;
        foreach ($unassigned as $task) {
            $requiredExtraMinutes += (int)($task['visit_duration_min'] ?? 45) + 20;
        }
        $requiredExtraMinutes += max(0, (int)$summary['overtime_min'] - $availableOvertimeMinutes);
        $recommended = !empty($unassigned) || $daysOverMax > 0;

        return [
            'recommended' => $recommended,
            'days_over_max' => $daysOverMax,
            'zone' => $zone,
            'normal_minutes' => $targetWorkMin * max(1, $periodDays),
            'normal_human' => self::human_minutes($targetWorkMin * max(1, $periodDays)),
            'overtime_minutes' => (int)$summary['overtime_min'],
            'overtime_human' => self::human_minutes((int)$summary['overtime_min']),
            'max_overtime_per_day_minutes' => 150,
            'max_overtime_per_day_human' => self::human_minutes(150),
            'available_overtime_minutes' => $availableOvertimeMinutes,
            'available_overtime_human' => self::human_minutes($availableOvertimeMinutes),
            'overloaded_days' => $overloadedDays,
            'unassigned_count' => count($unassigned),
            'unassigned_minutes' => max(0, $requiredExtraMinutes),
            'unassigned_human' => self::human_minutes(max(0, $requiredExtraMinutes)),
            'second_member_share_minutes' => (int)max($requiredExtraMinutes, 0),
            'second_member_share_human' => self::human_minutes((int)max($requiredExtraMinutes, 0)),
            'ratio_vs_normal' => $targetWorkMin > 0 && $periodDays > 0 ? round(((int)$summary['overtime_min'] / ($targetWorkMin * $periodDays)) * 100, 1) : 0,
        ];
    }
    private static function merge_reinforcement_summaries(array $weeks, array $unassigned, array $summary, int $periodDays, int $targetWorkMin): array {
        if (!$weeks) return self::build_reinforcement_summary([], $unassigned, $targetWorkMin, $periodDays);
        $zoneCounts = [];
        $overloadedDays = 0;
        $daysOverMax = 0;
        $availableOvertimeMinutes = 0;
        foreach ($weeks as $week) {
            if (!empty($week['zone'])) $zoneCounts[$week['zone']] = ($zoneCounts[$week['zone']] ?? 0) + 1;
            $overloadedDays += (int)($week['overloaded_days'] ?? 0);
            $daysOverMax += (int)($week['days_over_max'] ?? 0);
            $availableOvertimeMinutes += (int)($week['available_overtime_minutes'] ?? 0);
        }
        arsort($zoneCounts);
        $zone = $zoneCounts ? (string)array_key_first($zoneCounts) : self::pick_reinforcement_zone($unassigned);
        $requiredExtraMinutes = 0;
        foreach ($unassigned as $task) {
            $requiredExtraMinutes += (int)($task['visit_duration_min'] ?? 45) + 20;
        }
        $requiredExtraMinutes += max(0, (int)($summary['overtime_min'] ?? 0) - $availableOvertimeMinutes);
        $recommended = !empty($unassigned) || $daysOverMax > 0;

        return [
            'recommended' => $recommended,
            'days_over_max' => $daysOverMax,
            'zone' => $zone,
            'normal_minutes' => $targetWorkMin * max(1, $periodDays),
            'normal_human' => self::human_minutes($targetWorkMin * max(1, $periodDays)),
            'overtime_minutes' => (int)($summary['overtime_min'] ?? 0),
            'overtime_human' => self::human_minutes((int)($summary['overtime_min'] ?? 0)),
            'max_overtime_per_day_minutes' => 150,
            'max_overtime_per_day_human' => self::human_minutes(150),
            'available_overtime_minutes' => $availableOvertimeMinutes,
            'available_overtime_human' => self::human_minutes($availableOvertimeMinutes),
            'overloaded_days' => $overloadedDays,
            'unassigned_count' => count($unassigned),
            'unassigned_minutes' => max(0, $requiredExtraMinutes),
            'unassigned_human' => self::human_minutes(max(0, $requiredExtraMinutes)),
            'second_member_share_minutes' => max(0, $requiredExtraMinutes),
            'second_member_share_human' => self::human_minutes(max(0, $requiredExtraMinutes)),
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