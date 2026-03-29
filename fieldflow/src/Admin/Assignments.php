<?php
namespace RoutesPro\Admin;

use RoutesPro\Support\AssignmentMatrix;
use RoutesPro\Support\AssignmentResolver;
use RoutesPro\Forms\BindingResolver;
use RoutesPro\Forms\Forms as FormsModule;

class Assignments {
    private static function get_roles(){
        $roles = get_option('routespro_roles');
        if (!is_array($roles) || !$roles) {
            $roles = ['driver','merchandiser','sales','supervisor','implementador','operacional','owner'];
            update_option('routespro_roles', $roles);
        }
        $roles = array_values(array_unique(array_filter(array_map(function($r){ return trim(sanitize_text_field($r)); }, $roles))));
        foreach (['operacional','owner'] as $required_role) {
            if (!in_array($required_role, $roles, true)) $roles[] = $required_role;
        }
        return array_values(array_unique($roles));
    }

    private static function save_roles($roles){
        $clean = array_values(array_unique(array_filter(array_map(function($r){ return trim(sanitize_text_field($r)); }, (array)$roles))));
        update_option('routespro_roles', $clean);
        return $clean;
    }

    private static function admin_url(array $args = []): string {
        return add_query_arg($args, admin_url('admin.php?page=routespro-assignments-hub'));
    }

    private static function selected_multi(array $selected, int $candidate): string {
        return in_array($candidate, $selected, true) ? ' selected' : '';
    }

    private static function redirect_with_state(string $tab, array $args = []): void {
        $base = [
            'page' => 'routespro-assignments-hub',
            'tab' => $tab,
        ];
        foreach (['client_id','project_id','route_id'] as $key) {
            $value = absint($_POST[$key] ?? $_GET[$key] ?? 0);
            if ($value > 0) $base[$key] = $value;
        }
        $url = add_query_arg(array_merge($base, $args), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function render_status_notice(): void {
        $status = sanitize_key($_GET['ff_status'] ?? '');
        if (!$status) return;
        $map = [
            'client_saved' => ['success', 'Atribuição de cliente guardada com sucesso.'],
            'project_saved' => ['success', 'Atribuição de projeto guardada com sucesso.'],
            'route_saved' => ['success', 'Atribuição de rota guardada com sucesso.'],
            'binding_saved' => ['success', 'Ligação de formulário guardada com sucesso.'],
            'binding_deleted' => ['success', 'Ligação de formulário removida com sucesso.'],
            'roles_saved' => ['success', 'Função adicionada com sucesso.'],
            'save_error' => ['error', 'Ocorreu um problema ao gravar. Verifica os dados e tenta novamente.'],
            'binding_invalid' => ['error', 'Para guardar a ligação do formulário tens de escolher um formulário e pelo menos um âmbito.'],
        ];
        if (empty($map[$status])) return;
        [$kind, $message] = $map[$status];
        echo '<div class="notice notice-' . esc_attr($kind) . ' is-dismissible"><p><strong>' . esc_html($message) . '</strong></p></div>';
    }

    private static function render_user_options(array $users, array $selected = []): string {
        $html = '';
        foreach ($users as $user) {
            $label = ($user->display_name ?: $user->user_login) . ' [' . $user->user_login . ']';
            if (!empty($user->user_email)) $label .= ' • ' . $user->user_email;
            $html .= '<option value="' . (int)$user->ID . '"' . self::selected_multi($selected, (int)$user->ID) . '>' . esc_html($label) . '</option>';
        }
        return $html;
    }

    private static function resolve_selected_role(array $roles, string $current_role, string $fallback): array {
        $selected_role = trim(sanitize_text_field($current_role));
        if ($selected_role === '') $selected_role = $fallback;
        if (!in_array($selected_role, $roles, true)) $roles[] = $selected_role;
        return [array_values(array_unique($roles)), $selected_role];
    }


    private static function find_client_name(array $clients, int $client_id): string {
        foreach ($clients as $client) {
            if ((int)($client['id'] ?? 0) == $client_id) return (string)($client['name'] ?? '');
        }
        return '';
    }

    private static function find_project_name(array $projects, int $project_id): string {
        foreach ($projects as $project) {
            if ((int)($project['id'] ?? 0) == $project_id) return (string)($project['name'] ?? '');
        }
        return '';
    }

    private static function render_tab_nav(string $tab): void {
        $tabs = [
            'overview' => 'Visão geral',
            'clients' => 'Clientes',
            'projects' => 'Projetos',
            'routes' => 'Rotas',
            'forms' => 'Formulários',
        ];
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px">';
        foreach ($tabs as $slug => $label) {
            $class = $slug === $tab ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(self::admin_url(['tab' => $slug])) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function handle_post_actions(): void {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;

        if (!empty($_POST['routespro_role_nonce']) && wp_verify_nonce($_POST['routespro_role_nonce'], 'routespro_role_manage')) {
            $new = sanitize_text_field($_POST['new_role'] ?? '');
            if ($new !== '') {
                self::save_roles(array_merge(self::get_roles(), [$new]));
                self::redirect_with_state(sanitize_key($_GET['tab'] ?? 'overview'), ['ff_status' => 'roles_saved']);
            }
        }

        $action = sanitize_key($_POST['routespro_assignment_hub_action'] ?? '');
        if (!$action) return;
        check_admin_referer('routespro_assignment_hub_' . $action);
        $tab = sanitize_key($_GET['tab'] ?? 'overview');

        switch ($action) {
            case 'save_client_scope':
                $client_id = absint($_POST['client_id'] ?? 0);
                $user_ids = array_map('absint', (array)($_POST['associated_user_ids'] ?? []));
                AssignmentResolver::save_client_user_ids($client_id, $user_ids);
                self::redirect_with_state('clients', ['client_id' => $client_id, 'ff_status' => $wpdb->last_error ? 'save_error' : 'client_saved']);
                break;
            case 'save_project_scope':
                $project_id = absint($_POST['project_id'] ?? 0);
                $user_ids = array_map('absint', (array)($_POST['associated_user_ids'] ?? []));
                $owner_ids = array_map('absint', (array)($_POST['owner_user_ids'] ?? []));
                $owner_role = sanitize_text_field($_POST['owner_role'] ?? 'owner');
                AssignmentResolver::save_project_assignments($project_id, $user_ids, $owner_ids, $owner_role ?: 'owner');
                self::redirect_with_state('projects', ['project_id' => $project_id, 'ff_status' => $wpdb->last_error ? 'save_error' : 'project_saved']);
                break;
            case 'save_route_scope':
                $route_id = absint($_POST['route_id'] ?? 0);
                $owner_user_id = absint($_POST['owner_user_id'] ?? 0);
                $team_user_ids = array_map('absint', (array)($_POST['team_user_ids'] ?? []));
                $team_role = sanitize_text_field($_POST['team_role'] ?? 'operacional');
                AssignmentResolver::save_route_assignments($route_id, $owner_user_id, $team_user_ids, $team_role ?: 'operacional');
                self::redirect_with_state('routes', ['route_id' => $route_id, 'ff_status' => $wpdb->last_error ? 'save_error' : 'route_saved']);
                break;
            case 'save_form_binding':
                $data = [
                    'form_id' => absint($_POST['form_id'] ?? 0),
                    'client_id' => absint($_POST['client_id'] ?? 0),
                    'project_id' => absint($_POST['project_id'] ?? 0),
                    'route_id' => absint($_POST['route_id'] ?? 0),
                    'stop_id' => absint($_POST['stop_id'] ?? 0),
                    'location_id' => absint($_POST['location_id'] ?? 0),
                    'mode' => sanitize_key($_POST['mode'] ?? 'route_and_form'),
                    'priority' => max(0, min(999, (int)($_POST['priority'] ?? 10))),
                    'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                    'created_at' => current_time('mysql'),
                ];
                if ($data['form_id'] && ($data['client_id'] || $data['project_id'] || $data['route_id'] || $data['stop_id'] || $data['location_id'])) {
                    $wpdb->insert(BindingResolver::table(), $data, ['%d','%d','%d','%d','%d','%d','%s','%d','%d','%s']);
                    self::redirect_with_state('forms', [
                        'client_id' => $data['client_id'],
                        'project_id' => $data['project_id'],
                        'route_id' => $data['route_id'],
                        'ff_status' => $wpdb->last_error ? 'save_error' : 'binding_saved'
                    ]);
                } else {
                    self::redirect_with_state('forms', ['ff_status' => 'binding_invalid']);
                }
                break;
            case 'delete_form_binding':
                $id = absint($_POST['binding_id'] ?? 0);
                if ($id) {
                    $wpdb->delete(BindingResolver::table(), ['id' => $id], ['%d']);
                }
                self::redirect_with_state('forms', ['ff_status' => $wpdb->last_error ? 'save_error' : 'binding_deleted']);
                break;
        }
    }

    public static function render_hub() {
        if (!current_user_can('routespro_manage')) return;
        self::handle_post_actions();
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $tab = sanitize_key($_GET['tab'] ?? 'overview');
        $roles = self::get_roles();
        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
        $clients = $wpdb->get_results("SELECT id, name, meta_json FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
        $projects = $wpdb->get_results("SELECT id, client_id, name, meta_json FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: [];
        $routes = $wpdb->get_results("SELECT id, client_id, project_id, owner_user_id, date, status FROM {$px}routes ORDER BY date DESC, id DESC LIMIT 300", ARRAY_A) ?: [];
        $forms = $wpdb->get_results('SELECT id, title, status FROM ' . FormsModule::table() . ' ORDER BY id DESC LIMIT 200', ARRAY_A) ?: [];
        $stops = $wpdb->get_results("SELECT rs.id, rs.route_id, rs.seq, COALESCE(l.name,'PDV') AS location_name FROM {$px}route_stops rs LEFT JOIN {$px}locations l ON l.id=rs.location_id ORDER BY rs.id DESC LIMIT 300", ARRAY_A) ?: [];
        $locations = $wpdb->get_results("SELECT id, name FROM {$px}locations ORDER BY name ASC LIMIT 500", ARRAY_A) ?: [];

        $selected_client_id = absint($_GET['client_id'] ?? ($_POST['client_id'] ?? 0));
        $selected_project_id = absint($_GET['project_id'] ?? ($_POST['project_id'] ?? 0));
        $selected_route_id = absint($_GET['route_id'] ?? ($_POST['route_id'] ?? 0));
        if (!$selected_project_id && $selected_route_id) {
            foreach ($routes as $route) if ((int)$route['id'] === $selected_route_id) $selected_project_id = (int)($route['project_id'] ?? 0);
        }
        if (!$selected_client_id && $selected_project_id) {
            foreach ($projects as $project) if ((int)$project['id'] === $selected_project_id) $selected_client_id = (int)($project['client_id'] ?? 0);
        }
        if (!$selected_client_id && $selected_route_id) {
            foreach ($routes as $route) if ((int)$route['id'] === $selected_route_id) $selected_client_id = (int)($route['client_id'] ?? 0);
        }

        echo '<div class="wrap">';
        Branding::render_header('Centro de Atribuições');
        self::render_status_notice();
        echo '<style>
            .ff-hub-grid{display:grid;grid-template-columns:280px minmax(0,1fr);gap:18px;align-items:start}
            .ff-hub-sidebar,.ff-hub-main{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px}
            .ff-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}
            .ff-kpi{border:1px solid #e5e7eb;border-radius:14px;padding:16px;background:linear-gradient(180deg,#fff,#f8fafc)}
            .ff-kpi strong{display:block;font-size:24px;line-height:1.1;margin-top:6px}
            .ff-chip{display:inline-block;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-weight:600;font-size:12px;margin-right:8px;margin-bottom:8px}
            .ff-section-title{margin:0 0 12px 0;font-size:18px}
            .ff-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
            .ff-note{color:#64748b;font-size:13px}
            .ff-sticky{position:sticky;top:46px}
            .ff-list{margin:0;padding-left:18px}
            .ff-list li{margin:0 0 6px 0}
            .ff-table td code{white-space:normal;word-break:break-word}
            @media (max-width: 980px){.ff-hub-grid{grid-template-columns:1fr}.ff-sticky{position:static}}
        </style>';
        self::render_tab_nav($tab);
        echo '<div class="ff-hub-grid">';
        echo '<aside class="ff-hub-sidebar ff-sticky">';
        echo '<h2 class="ff-section-title">Contexto de trabalho</h2>';
        echo '<form method="get" data-ff-context="sidebar">';
        echo '<input type="hidden" name="page" value="routespro-assignments-hub">';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        echo '<p><label><strong>Cliente</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
        foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
        echo '</select></label></p>';
        echo '<p><label><strong>Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Todos</option>';
        foreach ($projects as $project) {
            $hidden = $selected_client_id && (int)$project['client_id'] !== $selected_client_id ? ' style="display:none"' : '';
            echo '<option value="' . (int)$project['id'] . '" data-client-id="' . (int)$project['client_id'] . '"' . $hidden . selected($selected_project_id, (int)$project['id'], false) . '>' . esc_html($project['name'] . ' #' . (int)$project['id']) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><strong>Rota</strong><br><select name="route_id" data-ff-role="route" style="width:100%"><option value="0">Todas</option>';
        foreach ($routes as $route) {
            $hidden = '';
            if ($selected_client_id && (int)$route['client_id'] !== $selected_client_id) $hidden = ' style="display:none"';
            if ($selected_project_id && (int)$route['project_id'] !== $selected_project_id) $hidden = ' style="display:none"';
            $label = '#' . (int)$route['id'] . ' · ' . ($route['date'] ?: 'sem data') . ' · ' . ($route['status'] ?: '');
            echo '<option value="' . (int)$route['id'] . '" data-client-id="' . (int)$route['client_id'] . '" data-project-id="' . (int)$route['project_id'] . '"' . $hidden . selected($selected_route_id, (int)$route['id'], false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><button class="button button-primary">Aplicar contexto</button></p>';
        echo '</form>';
        echo '<hr style="margin:18px 0">';
        echo '<div class="ff-note"><strong>Fase 1:</strong> as atribuições passam a estar centradas aqui. Os ecrãs antigos continuam disponíveis por URL, mas deixam de ser o caminho principal.</div>';
        echo '</aside>';
        echo '<section class="ff-hub-main">';

        if ($tab === 'overview') {
            $counts = AssignmentResolver::get_overview_counts();
            echo '<h2 class="ff-section-title">Resumo operacional</h2>';
            echo '<div class="ff-kpi-grid">';
            $labels = [
                'clients' => 'Clientes',
                'projects' => 'Projetos',
                'routes' => 'Rotas',
                'project_assignments' => 'Owners de projeto',
                'route_assignments' => 'Atribuições de rota',
                'form_bindings' => 'Ligações de formulários',
            ];
            foreach ($labels as $key => $label) {
                echo '<div class="ff-kpi"><span class="ff-note">' . esc_html($label) . '</span><strong>' . (int)($counts[$key] ?? 0) . '</strong></div>';
            }
            echo '</div>';
            echo '<div class="routespro-card" style="margin-top:18px">';
            echo '<h3 style="margin-top:0">Modelo recomendado</h3>';
            echo '<span class="ff-chip">1. Cliente define universo</span>';
            echo '<span class="ff-chip">2. Projeto define equipa</span>';
            echo '<span class="ff-chip">3. Rota define owner e exceções</span>';
            echo '<span class="ff-chip">4. Formulários herdam contexto</span>';
            echo '<ul class="ff-list"><li>Cliente: visibilidade macro para equipa, cliente e supervisão.</li><li>Projeto: equipa operacional e responsáveis ativos.</li><li>Rota: owner operacional e equipa do dia.</li><li>Formulários: regra automática por cliente, projeto, rota, paragem ou local.</li></ul>';
            echo '</div>';
        } elseif ($tab === 'clients') {
            $selected = AssignmentResolver::get_client_user_ids($selected_client_id);
            echo '<h2 class="ff-section-title">Atribuição de cliente</h2>';
            echo '<p class="ff-note"><strong>Aba Clientes.</strong> Aqui defines a base de acesso ao cliente. O que guardares aqui deve servir de universo para projetos, rotas e formulários.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . ($selected_client_id ? esc_html(count($selected) . ' utilizadores associados ao cliente selecionado.') : 'Seleciona um cliente para veres o estado guardado.') . '</div>';
            echo '<form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_client_scope');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_client_scope">';
            echo '<input type="hidden" name="tab" value="clients">';
            echo '<div class="ff-form-grid">';
            echo '<p><label><strong>Cliente</strong><br><select name="client_id" style="width:100%" required><option value="">Seleciona</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Utilizadores associados</strong><br><select name="associated_user_ids[]" multiple size="12" style="width:min(100%,760px)">' . self::render_user_options($users, $selected) . '</select></label><br><span class="ff-note">Estes utilizadores passam a ver o cliente e servem de base para o resto das heranças.</span></p>';
            echo '</div>';
            submit_button('Guardar atribuição de cliente');
            echo '</form>';
        } elseif ($tab === 'projects') {
            $project = AssignmentResolver::get_project_context($selected_project_id);
            $associated = array_map('intval', (array)($project['associated_user_ids'] ?? []));
            $owners = array_map(function($row){ return (int)($row['user_id'] ?? 0); }, array_filter((array)($project['owners'] ?? []), fn($row) => !empty($row['is_active'])));
            $project_owner_role = 'owner';
            foreach ((array)($project['owners'] ?? []) as $project_owner_row) {
                if (!empty($project_owner_row['is_active']) && !empty($project_owner_row['role'])) { $project_owner_role = (string)$project_owner_row['role']; break; }
            }
            [$project_role_options, $project_owner_role] = self::resolve_selected_role($roles, $project_owner_role, 'owner');
            echo '<h2 class="ff-section-title">Atribuição de campanha / projeto</h2>';
            echo '<p class="ff-note"><strong>Aba Campanhas / Projetos.</strong> Primeiro filtras o cliente, depois a campanha. O save é sempre feito para a campanha selecionada, nunca para todas.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . ($selected_project_id ? esc_html((self::find_client_name($clients, (int)($project['client_id'] ?? $selected_client_id)) ?: 'Sem cliente') . ' · ' . (self::find_project_name($projects, $selected_project_id) ?: ('Campanha #' . $selected_project_id)) . ' · ' . count($associated) . ' utilizadores com acesso, ' . count($owners) . ' responsáveis ativos.') : 'Seleciona uma campanha para veres o estado guardado.') . '</div>';
            echo '<form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_project_scope');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_project_scope">';
            echo '<input type="hidden" name="tab" value="projects">';
            echo '<div class="ff-form-grid" data-ff-context="project-form">';
            echo '<p><label><strong>Cliente / Marca</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label><br><span class="ff-note">Filtra as campanhas deste cliente.</span></p>';
            echo '<p><label><strong>Campanha / Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%" required><option value="">Seleciona</option>';
            foreach ($projects as $item) { $clientName = self::find_client_name($clients, (int)$item['client_id']); echo '<option value="' . (int)$item['id'] . '" data-client-id="' . (int)$item['client_id'] . '"' . selected($selected_project_id, (int)$item['id'], false) . '>' . esc_html(($clientName ? $clientName . ' · ' : '') . $item['name'] . ' #' . (int)$item['id']) . '</option>'; }
            echo '</select></label><br><span class="ff-note">O save fica preso à campanha selecionada.</span></p>';
            echo '<p><label><strong>Função dos responsáveis</strong><br><select name="owner_role" style="width:100%">';
            foreach ($project_role_options as $role) echo '<option value="' . esc_attr($role) . '"' . selected($role, $project_owner_role, false) . '>' . esc_html($role) . '</option>';
            echo '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Utilizadores com acesso ao projeto</strong><br><select name="associated_user_ids[]" multiple size="10" style="width:min(100%,760px)">' . self::render_user_options($users, $associated) . '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Responsáveis ativos do projeto</strong><br><select name="owner_user_ids[]" multiple size="8" style="width:min(100%,760px)">' . self::render_user_options($users, $owners) . '</select></label><br><span class="ff-note">Estes users alimentam BO, front e sincronização operacional da campanha.</span></p>';
            echo '</div>';
            submit_button('Guardar atribuição de projeto');
            echo '</form>';
        } elseif ($tab === 'routes') {
            $route = AssignmentResolver::get_route_context($selected_route_id);
            $owner_id = (int)($route['owner_user_id'] ?? 0);
            $team_ids = array_map(function($row){ return (int)($row['user_id'] ?? 0); }, array_filter((array)($route['assignments'] ?? []), fn($row) => !empty($row['is_active']) && (($row['role'] ?? '') !== 'owner')));
            $route_meta = json_decode((string)($route['meta_json'] ?? ''), true);
            if (!is_array($route_meta)) $route_meta = [];
            $route_team_role = (string)($route_meta['default_team_role'] ?? 'operacional');
            foreach ((array)($route['assignments'] ?? []) as $route_assignment_row) {
                if (!empty($route_assignment_row['is_active']) && (($route_assignment_row['role'] ?? '') !== 'owner') && !empty($route_assignment_row['role'])) { $route_team_role = (string)$route_assignment_row['role']; break; }
            }
            [$route_role_options, $route_team_role] = self::resolve_selected_role($roles, $route_team_role, 'operacional');
            $assignable = AssignmentMatrix::get_assignable_users((int)($route['client_id'] ?? $selected_client_id), (int)($route['project_id'] ?? $selected_project_id));
            if (!$assignable) $assignable = $users;
            echo '<h2 class="ff-section-title">Atribuição de rota</h2>';
            echo '<p class="ff-note"><strong>Aba Rotas.</strong> Primeiro escolhes cliente, depois campanha, e só depois a rota. Todos os filtros são dinâmicos e o save fica preso à rota selecionada.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . ($selected_route_id ? esc_html('owner: ' . ($owner_id ? '#' . $owner_id : 'sem owner') . ', equipa adicional: ' . count($team_ids) . ' utilizadores.') : 'Seleciona cliente, campanha e rota para veres o estado guardado.') . '</div>';
            echo '<form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_route_scope');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_route_scope">';
            echo '<input type="hidden" name="tab" value="routes">';
            echo '<div class="ff-form-grid" data-ff-context="route-form">';
            echo '<p><label><strong>Cliente / Marca</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected((int)($route['client_id'] ?? $selected_client_id), (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Campanha / Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Todos</option>';
            foreach ($projects as $item) { $clientName = self::find_client_name($clients, (int)$item['client_id']); echo '<option value="' . (int)$item['id'] . '" data-client-id="' . (int)$item['client_id'] . '"' . selected((int)($route['project_id'] ?? $selected_project_id), (int)$item['id'], false) . '>' . esc_html(($clientName ? $clientName . ' · ' : '') . $item['name'] . ' #' . (int)$item['id']) . '</option>'; }
            echo '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Rota</strong><br><select name="route_id" data-ff-role="route" style="width:100%" required><option value="">Seleciona</option>';
            foreach ($routes as $item) {
                $projectName = self::find_project_name($projects, (int)($item['project_id'] ?? 0));
                $clientId = (int)($item['client_id'] ?? 0);
                $projectId = (int)($item['project_id'] ?? 0);
                $label = '#' . (int)$item['id'] . ' · ' . ($projectName ?: 'Sem campanha') . ' · ' . ($item['date'] ?: 'sem data') . ' · ' . ($item['status'] ?: '');
                echo '<option value="' . (int)$item['id'] . '" data-client-id="' . $clientId . '" data-project-id="' . $projectId . '"' . selected($selected_route_id, (int)$item['id'], false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label><br><span class="ff-note">A lista de rotas reage ao cliente e à campanha.</span></p>';
            echo '<p><label><strong>Owner operacional</strong><br><select name="owner_user_id" style="width:100%"><option value="0">Sem owner</option>' . self::render_user_options($assignable, $owner_id ? [$owner_id] : []) . '</select></label></p>';
            echo '<p><label><strong>Função da equipa</strong><br><select name="team_role" style="width:100%">';
            foreach ($route_role_options as $role) echo '<option value="' . esc_attr($role) . '"' . selected($role, $route_team_role, false) . '>' . esc_html($role) . '</option>';
            echo '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Equipa adicional da rota</strong><br><select name="team_user_ids[]" multiple size="10" style="width:min(100%,760px)">' . self::render_user_options($assignable, $team_ids) . '</select></label><br><span class="ff-note">O owner é sempre sincronizado, mesmo que não o seleciones novamente aqui.</span></p>';
            echo '</div>';
            submit_button('Guardar atribuição de rota');
            echo '</form>';
        } elseif ($tab === 'forms') {
            $bindings = AssignmentResolver::get_form_bindings(['client_id' => $selected_client_id, 'project_id' => $selected_project_id, 'route_id' => $selected_route_id]);
            echo '<h2 class="ff-section-title">Formulários por contexto</h2>';
            echo '<p class="ff-note"><strong>Aba Formulários.</strong> Primeiro filtras cliente e campanha, depois a rota. O save fica ligado exatamente ao contexto que selecionares.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . esc_html(count($bindings) . ' ligações encontradas para o contexto atual.') . '</div>';
            echo '<form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_form_binding');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_form_binding">';
            echo '<input type="hidden" name="tab" value="forms">';
            echo '<div class="ff-form-grid" data-ff-context="binding-form">';
            echo '<p><label><strong>Formulário</strong><br><select name="form_id" style="width:100%" required><option value="">Seleciona</option>';
            foreach ($forms as $form) echo '<option value="' . (int)$form['id'] . '">' . esc_html(($form['title'] ?: 'Sem título') . ' #' . (int)$form['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Modo</strong><br><select name="mode" style="width:100%"><option value="route_and_form">Rota e formulário</option><option value="form_only">Só formulário</option><option value="route_only">Só rota</option></select></label></p>';
            echo '<p><label><strong>Prioridade</strong><br><input type="number" name="priority" value="10" min="0" max="999" style="width:120px"></label></p>';
            echo '<p><label><strong>Cliente / Marca</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Nenhum</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Campanha / Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Nenhum</option>';
            foreach ($projects as $project) echo '<option value="' . (int)$project['id'] . '" data-client-id="' . (int)$project['client_id'] . '"' . selected($selected_project_id, (int)$project['id'], false) . '>' . esc_html($project['name'] . ' #' . (int)$project['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Rota</strong><br><select name="route_id" data-ff-role="route" style="width:100%"><option value="0">Nenhuma</option>';
            foreach ($routes as $route) {
                $label = '#' . (int)$route['id'] . ' · ' . ($route['date'] ?: 'sem data');
                echo '<option value="' . (int)$route['id'] . '" data-client-id="' . (int)($route['client_id'] ?? 0) . '" data-project-id="' . (int)($route['project_id'] ?? 0) . '"' . selected($selected_route_id, (int)$route['id'], false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label><strong>Paragem</strong><br><select name="stop_id" style="width:100%"><option value="0">Nenhuma</option>';
            foreach ($stops as $stop) {
                $label = '#' . (int)$stop['id'] . ' · Rota #' . (int)$stop['route_id'] . ' · ' . ($stop['location_name'] ?: 'PDV');
                echo '<option value="' . (int)$stop['id'] . '">' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label><strong>Local</strong><br><select name="location_id" style="width:100%"><option value="0">Nenhum</option>';
            foreach ($locations as $location) echo '<option value="' . (int)$location['id'] . '">' . esc_html($location['name'] . ' #' . (int)$location['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><input type="checkbox" name="is_active" value="1" checked> Ligação ativa</label></p>';
            echo '</div>';
            submit_button('Guardar ligação de formulário');
            echo '</form>';

            echo '<h3 style="margin-top:28px">Ligações existentes</h3>';
            echo '<table class="widefat striped ff-table"><thead><tr><th>ID</th><th>Formulário</th><th>Âmbito</th><th>Modo</th><th>Prioridade</th><th>Ação</th></tr></thead><tbody>';
            if (!$bindings) {
                echo '<tr><td colspan="6">Sem ligações para este contexto.</td></tr>';
            } else {
                foreach ($bindings as $row) {
                    $scope = [];
                    if (!empty($row['client_id'])) $scope[] = 'Cliente ' . ($row['client_name'] ?: '#' . (int)$row['client_id']);
                    if (!empty($row['project_id'])) $scope[] = 'Projeto ' . ($row['project_name'] ?: '#' . (int)$row['project_id']);
                    if (!empty($row['route_id'])) $scope[] = 'Rota #' . (int)$row['route_id'] . ' ' . ($row['route_date'] ?: '');
                    if (!empty($row['location_id'])) $scope[] = 'Local ' . ($row['location_name'] ?: '#' . (int)$row['location_id']);
                    if (!empty($row['stop_id'])) $scope[] = 'Paragem #' . (int)$row['stop_id'];
                    echo '<tr><td>' . (int)$row['id'] . '</td><td>' . esc_html($row['form_title'] ?: ('#' . (int)$row['form_id'])) . '</td><td>' . esc_html(implode(' | ', $scope)) . '</td><td>' . esc_html($row['mode']) . '</td><td>' . (int)$row['priority'] . '</td><td><form method="post" style="margin:0">';
                    wp_nonce_field('routespro_assignment_hub_delete_form_binding');
                    echo '<input type="hidden" name="routespro_assignment_hub_action" value="delete_form_binding"><input type="hidden" name="binding_id" value="' . (int)$row['id'] . '"><button class="button button-small" onclick="return confirm(\'Remover ligação?\')">Apagar</button></form></td></tr>';
                }
            }
            echo '</tbody></table>';
        }

        echo '</section></div>';
        echo '<script>(function(){
            function syncSelectVisibility(root){
                const client = root.querySelector("[data-ff-role=client]");
                const project = root.querySelector("[data-ff-role=project]");
                const route = root.querySelector("[data-ff-role=route]");
                const clientVal = client ? (client.value || "0") : "0";
                const projectVal = project ? (project.value || "0") : "0";
                if (project) {
                    Array.from(project.options).forEach(function(opt){
                        if (!opt.dataset.clientId) return;
                        const visible = clientVal === "0" || opt.dataset.clientId === clientVal;
                        opt.hidden = !visible;
                        if (!visible && opt.selected) project.value = "0";
                    });
                }
                if (route) {
                    Array.from(route.options).forEach(function(opt){
                        if (!opt.dataset.clientId && !opt.dataset.projectId) return;
                        const matchClient = clientVal === "0" || opt.dataset.clientId === clientVal;
                        const matchProject = projectVal === "0" || opt.dataset.projectId === projectVal;
                        const visible = matchClient && matchProject;
                        opt.hidden = !visible;
                        if (!visible && opt.selected) route.value = "0";
                    });
                }
            }
            function bindContext(root){
                if (!root) return;
                const client = root.querySelector("[data-ff-role=client]");
                const project = root.querySelector("[data-ff-role=project]");
                const route = root.querySelector("[data-ff-role=route]");
                syncSelectVisibility(root);
                if (client) client.addEventListener("change", function(){
                    if (project && project.selectedOptions[0] && project.selectedOptions[0].dataset.clientId && project.selectedOptions[0].dataset.clientId !== this.value) project.value = "0";
                    if (route) route.value = "0";
                    syncSelectVisibility(root);
                });
                if (project) project.addEventListener("change", function(){
                    const opt = this.selectedOptions[0];
                    if (client && opt && opt.dataset.clientId && client.value !== opt.dataset.clientId) client.value = opt.dataset.clientId;
                    if (route) route.value = "0";
                    syncSelectVisibility(root);
                });
                if (route) route.addEventListener("change", function(){
                    const opt = this.selectedOptions[0];
                    if (!opt) return;
                    if (client && opt.dataset.clientId && client.value !== opt.dataset.clientId) client.value = opt.dataset.clientId;
                    if (project && opt.dataset.projectId && project.value !== opt.dataset.projectId) project.value = opt.dataset.projectId;
                    syncSelectVisibility(root);
                });
            }
            document.querySelectorAll("[data-ff-context]").forEach(bindContext);
            const sidebar = document.querySelector(".ff-hub-sidebar form");
            if (sidebar) bindContext(sidebar);
        })();</script>';
        echo '</div>';
    }

    public static function render() {
        echo '<div class="wrap">';
        echo '<div class="notice notice-info"><p>Esta página legacy foi substituída pelo <strong>Centro de Atribuições</strong>.</p><p><a class="button button-primary" href="' . esc_url(self::admin_url()) . '">Abrir Centro de Atribuições</a></p></div>';
        echo '</div>';
    }
}
