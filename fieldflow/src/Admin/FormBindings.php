<?php
namespace RoutesPro\Admin;

use RoutesPro\Forms\BindingResolver;
use RoutesPro\Forms\Forms as FormsModule;

if (!defined('ABSPATH')) exit;

class FormBindings {
    public static function register_hooks() {
        add_action('admin_post_routespro_save_form_binding', [self::class, 'handle_save']);
        add_action('admin_post_routespro_delete_form_binding', [self::class, 'handle_delete']);
    }

    public static function render() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $forms = FormsModule::list_forms();
        $clients = $wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
        $projects = $wpdb->get_results("SELECT id,client_id,name FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: [];
        $routes = $wpdb->get_results("SELECT id,client_id,project_id,date,status FROM {$px}routes ORDER BY date DESC, id DESC LIMIT 500", ARRAY_A) ?: [];
        $stops = $wpdb->get_results("SELECT rs.id, rs.route_id, rs.location_id, rs.seq, l.name AS location_name FROM {$px}route_stops rs LEFT JOIN {$px}locations l ON l.id=rs.location_id ORDER BY rs.id DESC LIMIT 1000", ARRAY_A) ?: [];
        $locations = $wpdb->get_results("SELECT id,name FROM {$px}locations ORDER BY id DESC LIMIT 1000", ARRAY_A) ?: [];
        $rows = $wpdb->get_results(
            'SELECT b.*, f.title AS form_title, c.name AS client_name, p.name AS project_name, r.date AS route_date, l.name AS location_name, rs.seq AS stop_seq, ls.name AS stop_location_name
             FROM ' . BindingResolver::table() . ' b
             LEFT JOIN ' . FormsModule::table() . ' f ON f.id=b.form_id
             LEFT JOIN ' . $px . 'clients c ON c.id=b.client_id
             LEFT JOIN ' . $px . 'projects p ON p.id=b.project_id
             LEFT JOIN ' . $px . 'routes r ON r.id=b.route_id
             LEFT JOIN ' . $px . 'locations l ON l.id=b.location_id
             LEFT JOIN ' . $px . 'route_stops rs ON rs.id=b.stop_id
             LEFT JOIN ' . $px . 'locations ls ON ls.id=rs.location_id
             ORDER BY b.priority DESC, b.id DESC',
            ARRAY_A
        ) ?: [];

        echo '<div class="wrap">';
        Branding::render_header('Ligações de formulários', 'Fase 2, liga o formulário ao contexto certo, cliente, projeto, rota, stop ou local, sem partir o que já existe nas rotas.');
        if (isset($_GET['saved'])) echo '<div class="notice notice-success"><p>Ligação guardada.</p></div>';
        if (isset($_GET['deleted'])) echo '<div class="notice notice-success"><p>Ligação apagada.</p></div>';
        if (isset($_GET['error'])) echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['error']))) . '</p></div>';

        echo '<div class="routespro-card" style="margin-top:18px;padding:20px">';
        echo '<h2 style="margin-top:0">Nova ligação</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="routespro_save_form_binding">';
        wp_nonce_field('routespro_save_form_binding', 'routespro_save_form_binding_nonce');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="routespro_binding_form_id">Formulário</label></th><td><select name="form_id" id="routespro_binding_form_id" required><option value="">Seleciona</option>';
        foreach ($forms as $form) echo '<option value="' . (int) $form['id'] . '">' . esc_html($form['title'] . ' #' . $form['id']) . '</option>';
        echo '</select><p class="description">Podes usar depois o shortcode <code>[fieldflow_route_form]</code> para resolução automática pelo contexto.</p></td></tr>';
        echo '<tr><th scope="row">Âmbito</th><td>';
        echo '<label style="display:inline-block;min-width:260px">Cliente<br><select name="client_id" id="routespro_binding_client"><option value="">Nenhum</option>';
        foreach ($clients as $item) echo '<option value="' . (int) $item['id'] . '">' . esc_html($item['name']) . '</option>';
        echo '</select></label> ';
        echo '<label style="display:inline-block;min-width:260px">Projeto<br><select name="project_id" id="routespro_binding_project"><option value="">Nenhum</option>';
        foreach ($projects as $item) echo '<option value="' . (int) $item['id'] . '" data-client-id="' . (int) $item['client_id'] . '">' . esc_html($item['name'] . ' #' . $item['id']) . '</option>';
        echo '</select></label><br><br>';
        echo '<label style="display:inline-block;min-width:260px">Rota<br><select name="route_id" id="routespro_binding_route"><option value="">Nenhuma</option>';
        foreach ($routes as $item) {
            $label = '#' . (int) $item['id'] . ' · ' . ($item['date'] ?: 'sem data') . ' · ' . ($item['status'] ?: '');
            echo '<option value="' . (int) $item['id'] . '" data-client-id="' . (int) $item['client_id'] . '" data-project-id="' . (int) $item['project_id'] . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        echo '<label style="display:inline-block;min-width:320px">Paragem<br><select name="stop_id" id="routespro_binding_stop"><option value="">Nenhuma</option>';
        foreach ($stops as $item) {
            $label = '#' . (int) $item['id'] . ' · Rota #' . (int) $item['route_id'] . ' · ' . ($item['location_name'] ?: 'PDV') . ' · seq ' . (int) $item['seq'];
            echo '<option value="' . (int) $item['id'] . '" data-route-id="' . (int) $item['route_id'] . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label><br><br>';
        echo '<label style="display:inline-block;min-width:260px">Local<br><select name="location_id"><option value="">Nenhum</option>';
        foreach ($locations as $item) echo '<option value="' . (int) $item['id'] . '">' . esc_html($item['name'] . ' #' . $item['id']) . '</option>';
        echo '</select></label>';
        echo '</td></tr>';
        echo '<tr><th scope="row">Modo</th><td><select name="mode"><option value="route_and_form">Rota e formulário</option><option value="form_only">Só formulário</option><option value="route_only">Só rota, reservado para fases seguintes</option></select></td></tr>';
        echo '<tr><th scope="row">Prioridade</th><td><input type="number" name="priority" value="10" min="0" max="999" style="width:100px"> <label style="margin-left:14px"><input type="checkbox" name="is_active" value="1" checked> Activa</label><p class="description">A resolução segue a regra, stop, rota, projeto, cliente, local, e depois prioridade.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Guardar ligação');
        echo '</form></div>';

        echo '<div class="routespro-card" style="margin-top:18px;padding:20px">';
        echo '<h2 style="margin-top:0">Ligações existentes</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Formulário</th><th>Modo</th><th>Âmbito</th><th>Prioridade</th><th>Activo</th><th>Shortcode</th><th>Ações</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="8">Ainda não existem ligações.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $scope = [];
                if (!empty($row['stop_id'])) $scope[] = 'Paragem #' . (int) $row['stop_id'] . ' · ' . ($row['stop_location_name'] ?: 'PDV') . ' · seq ' . (int) ($row['stop_seq'] ?? 0);
                if (!empty($row['route_id'])) $scope[] = 'Rota #' . (int) $row['route_id'] . ' · ' . ($row['route_date'] ?: '');
                if (!empty($row['project_id'])) $scope[] = 'Projeto · ' . ($row['project_name'] ?: ('#' . (int) $row['project_id']));
                if (!empty($row['client_id'])) $scope[] = 'Cliente · ' . ($row['client_name'] ?: ('#' . (int) $row['client_id']));
                if (!empty($row['location_id'])) $scope[] = 'Local · ' . ($row['location_name'] ?: ('#' . (int) $row['location_id']));
                $del = wp_nonce_url(admin_url('admin-post.php?action=routespro_delete_form_binding&id=' . (int) $row['id']), 'routespro_delete_form_binding_' . (int) $row['id']);
                $shortcode = '[fieldflow_route_form';
                foreach (['client_id','project_id','route_id','stop_id','location_id'] as $field) {
                    if (!empty($row[$field])) $shortcode .= ' ' . $field . '="' . (int) $row[$field] . '"';
                }
                $shortcode .= ']';
                echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html($row['form_title'] ?: ('#' . (int) $row['form_id'])) . '</td><td>' . esc_html($row['mode']) . '</td><td>' . esc_html(implode(' | ', $scope)) . '</td><td>' . (int) $row['priority'] . '</td><td>' . (!empty($row['is_active']) ? 'Sim' : 'Não') . '</td><td><code>' . esc_html($shortcode) . '</code></td><td><a class="button button-small" href="' . esc_url($del) . '" onclick="return confirm(\'Apagar ligação?\')">Apagar</a></td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:12px;color:#64748b">Nesta fase já tens resolução e bindings. A integração automática no <code>[fieldflow_report_visit]</code> entra a seguir, sem partir o fluxo atual.</p>';
        echo '</div></div>';
        ?>
        <script>
        (function(){
          const client = document.getElementById('routespro_binding_client');
          const project = document.getElementById('routespro_binding_project');
          const route = document.getElementById('routespro_binding_route');
          const stop = document.getElementById('routespro_binding_stop');
          if(!client || !project || !route || !stop) return;
          const syncProject = () => {
            const cid = client.value || '';
            [...project.options].forEach((opt, i) => {
              if (i === 0) return;
              opt.hidden = !!cid && opt.dataset.clientId !== cid;
            });
          };
          const syncRoute = () => {
            const cid = client.value || '';
            const pid = project.value || '';
            [...route.options].forEach((opt, i) => {
              if (i === 0) return;
              const okClient = !cid || opt.dataset.clientId === cid;
              const okProject = !pid || opt.dataset.projectId === pid;
              opt.hidden = !(okClient && okProject);
            });
          };
          const syncStop = () => {
            const rid = route.value || '';
            [...stop.options].forEach((opt, i) => {
              if (i === 0) return;
              opt.hidden = !!rid && opt.dataset.routeId !== rid;
            });
          };
          client.addEventListener('change', () => { syncProject(); syncRoute(); });
          project.addEventListener('change', syncRoute);
          route.addEventListener('change', syncStop);
          syncProject(); syncRoute(); syncStop();
        })();
        </script>
        <?php
    }

    public static function handle_save() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        check_admin_referer('routespro_save_form_binding', 'routespro_save_form_binding_nonce');
        global $wpdb;
        $data = [
            'form_id' => absint($_POST['form_id'] ?? 0),
            'client_id' => absint($_POST['client_id'] ?? 0),
            'project_id' => absint($_POST['project_id'] ?? 0),
            'route_id' => absint($_POST['route_id'] ?? 0),
            'stop_id' => absint($_POST['stop_id'] ?? 0),
            'location_id' => absint($_POST['location_id'] ?? 0),
            'mode' => sanitize_key(wp_unslash($_POST['mode'] ?? 'route_and_form')),
            'priority' => max(0, min(999, (int) ($_POST['priority'] ?? 10))),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'created_at' => current_time('mysql'),
        ];
        if (!$data['form_id']) {
            wp_safe_redirect(admin_url('admin.php?page=routespro-form-bindings&error=formulario_invalido'));
            exit;
        }
        if (!$data['client_id'] && !$data['project_id'] && !$data['route_id'] && !$data['stop_id'] && !$data['location_id']) {
            wp_safe_redirect(admin_url('admin.php?page=routespro-form-bindings&error=define_pelo_menos_um_ambito'));
            exit;
        }
        if (!in_array($data['mode'], ['route_only','route_and_form','form_only'], true)) $data['mode'] = 'route_and_form';
        $inserted = $wpdb->insert(BindingResolver::table(), $data, ['%d','%d','%d','%d','%d','%d','%s','%d','%d','%s']);
        if ($inserted === false) {
            $msg = $wpdb->last_error ? rawurlencode($wpdb->last_error) : 'erro_ao_gravar_ligacao';
            wp_safe_redirect(admin_url('admin.php?page=routespro-form-bindings&error=' . $msg));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=routespro-form-bindings&saved=1'));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('routespro_delete_form_binding_' . $id);
        global $wpdb;
        if ($id) $wpdb->delete(BindingResolver::table(), ['id' => $id], ['%d']);
        wp_safe_redirect(admin_url('admin.php?page=routespro-form-bindings&deleted=1'));
        exit;
    }
}
