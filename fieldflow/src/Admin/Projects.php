<?php
namespace RoutesPro\Admin;

class Projects {
    private static function decode_meta($json): array {
        if (is_array($json)) return $json;
        if (!is_string($json) || trim($json) === '') return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function extract_user_ids(array $meta): array {
        $values = $meta['associated_user_ids'] ?? ($meta['user_ids'] ?? []);
        if (is_string($values)) $values = preg_split('/\s*,\s*/', trim($values));
        $out = [];
        if (is_array($values)) {
            foreach ($values as $value) {
                if (is_array($value)) $value = $value['user_id'] ?? $value['id'] ?? 0;
                $id = absint($value);
                if ($id) $out[$id] = $id;
            }
        }
        ksort($out);
        return array_values($out);
    }

    public static function render() {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;

        $px   = $wpdb->prefix;
        $tbl  = $px . 'routespro_projects';
        $tcli = $px . 'routespro_clients';
        $clients = $wpdb->get_results("SELECT id, name FROM {$tcli} ORDER BY name ASC", ARRAY_A);
        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);

        $emails_opt = \RoutesPro\Admin\Emails::get();
        $tpl_list   = isset($emails_opt['project_templates']) && is_array($emails_opt['project_templates'])
            ? $emails_opt['project_templates']
            : [
                'default' => [
                    'name'    => 'Padrão',
                    'subject' => $emails_opt['subject_completed'] ?? '[RoutesPro] Rota #{route_id} concluída ({date})',
                    'body'    => $emails_opt['body_completed']    ?? "Olá,\n\nA rota #{route_id} do cliente {client_name} foi CONCLUÍDA em {date}.\nResponsável: {user_name}.\nTotal de paragens: {stops}.\n\nCumprimentos,\nRoutesPro",
                ],
            ];
        $tpl_map = isset($emails_opt['project_template_map']) && is_array($emails_opt['project_template_map']) ? $emails_opt['project_template_map'] : [];

        if (!empty($_POST['routespro_projects_nonce']) && wp_verify_nonce($_POST['routespro_projects_nonce'], 'routespro_projects')) {
            $id = absint($_POST['id'] ?? 0);
            $associated_user_ids = array_values(array_filter(array_map('absint', (array)($_POST['associated_user_ids'] ?? []))));
            $existing_meta = [];
            if ($id) {
                $existing_meta_raw = $wpdb->get_var($wpdb->prepare("SELECT meta_json FROM {$tbl} WHERE id=%d", $id));
                $existing_meta = self::decode_meta((string)$existing_meta_raw);
            }
            $existing_meta['associated_user_ids'] = $associated_user_ids;
            $existing_meta['user_ids'] = $associated_user_ids;

            $data = [
                'client_id' => absint($_POST['client_id']),
                'name'      => sanitize_text_field($_POST['name'] ?? ''),
                'status'    => sanitize_text_field($_POST['status'] ?? 'active'),
                'meta_json' => wp_json_encode($existing_meta, JSON_UNESCAPED_UNICODE),
            ];

            if ($id) {
                $wpdb->update($tbl, $data, ['id' => $id], ['%d','%s','%s','%s'], ['%d']);
            } else {
                $wpdb->insert($tbl, $data, ['%d','%s','%s','%s']);
                $id = (int) $wpdb->insert_id;
            }

            $sel_tpl = sanitize_text_field($_POST['email_template_key'] ?? '');
            if ($id) {
                if ($sel_tpl && isset($tpl_list[$sel_tpl])) $tpl_map[$id] = $sel_tpl;
                else unset($tpl_map[$id]);
                $emails_opt['project_template_map'] = $tpl_map;
                update_option(\RoutesPro\Admin\Emails::OPT_KEY, $emails_opt);
            }

            echo '<div class="updated notice"><p>Guardado.</p></div>';
        }

        if (!empty($_GET['delete'])) {
            $del = absint($_GET['delete']);
            check_admin_referer('routespro_projects_del_' . $del);
            $wpdb->delete($tbl, ['id' => $del]);
            if (isset($tpl_map[$del])) {
                unset($tpl_map[$del]);
                $emails_opt['project_template_map'] = $tpl_map;
                update_option(\RoutesPro\Admin\Emails::OPT_KEY, $emails_opt);
            }
            echo '<div class="updated notice"><p>Removido.</p></div>';
        }

        $edit = null;
        if (!empty($_GET['edit'])) {
            $id   = absint($_GET['edit']);
            $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $id), ARRAY_A);
        }
        $edit_meta = self::decode_meta((string)($edit['meta_json'] ?? ''));
        $selected_users = self::extract_user_ids($edit_meta);

        $rows = $wpdb->get_results("SELECT p.*, c.name AS client_name FROM $tbl p LEFT JOIN {$tcli} c ON c.id = p.client_id ORDER BY p.id DESC LIMIT 500", ARRAY_A);

        echo '<div class="wrap"><h1>Projetos</h1>'; ?>

        <h2><?php echo $edit ? 'Editar Projeto' : 'Novo Projeto'; ?></h2>
        <form method="post">
            <?php wp_nonce_field('routespro_projects', 'routespro_projects_nonce'); ?>
            <input type="hidden" name="id" value="<?php echo esc_attr($edit['id'] ?? 0); ?>"/>

            <table class="form-table">
                <tr>
                    <th>Cliente</th>
                    <td>
                        <select name="client_id" required>
                            <option value="">--</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?php echo intval($c['id']); ?>" <?php selected(($edit['client_id'] ?? 0), $c['id']); ?>>
                                    <?php echo esc_html($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Nome</th>
                    <td><input name="name" class="regular-text" required value="<?php echo esc_attr($edit['name'] ?? ''); ?>"/></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <select name="status">
                            <?php foreach (['active', 'paused', 'archived'] as $s): ?>
                                <option value="<?php echo esc_attr($s); ?>" <?php selected(($edit['status'] ?? 'active'), $s); ?>><?php echo esc_html($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Users associados</th>
                    <td>
                        <select name="associated_user_ids[]" multiple size="10" style="min-width:320px;max-width:520px">
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo intval($user->ID); ?>" <?php selected(in_array((int)$user->ID, $selected_users, true)); ?>>
                                    <?php echo esc_html(($user->display_name ?: $user->user_login) . ($user->user_email ? ' • ' . $user->user_email : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Opcional. Se definires users aqui, esta campanha fica ainda mais restrita, acima do âmbito do cliente.</p>
                    </td>
                </tr>
                <tr>
                    <th>Template de E-mail</th>
                    <td>
                        <?php $current_tpl = (!empty($edit['id']) && isset($tpl_map[$edit['id']])) ? $tpl_map[$edit['id']] : ''; ?>
                        <select name="email_template_key">
                            <option value="">(Usar template global)</option>
                            <?php foreach ($tpl_list as $key => $tpl): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($current_tpl, $key); ?>><?php echo esc_html($tpl['name'] ?? $key); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Escolhe o template específico deste projeto. Se deixares vazio, será usado o template global.</p>
                    </td>
                </tr>
            </table>

            <p><button class="button button-primary">Guardar</button></p>
        </form>

        <h2 style="margin-top:2em">Lista</h2>
        <table class="widefat striped">
            <thead><tr><th>ID</th><th>Cliente</th><th>Projeto</th><th>Status</th><th>Users associados</th><th>Template</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $tpl_key = $tpl_map[$r['id']] ?? ''; $tpl_name = $tpl_key && isset($tpl_list[$tpl_key]) ? ($tpl_list[$tpl_key]['name'] ?? $tpl_key) : '—'; $meta = self::decode_meta((string)($r['meta_json'] ?? '')); $user_ids = self::extract_user_ids($meta); ?>
                <tr>
                    <td><?php echo intval($r['id']); ?></td>
                    <td><?php echo esc_html($r['client_name']); ?></td>
                    <td><?php echo esc_html($r['name']); ?></td>
                    <td><?php echo esc_html($r['status']); ?></td>
                    <td><?php echo esc_html($user_ids ? implode(', ', array_map('strval', $user_ids)) : '—'); ?></td>
                    <td><?php echo esc_html($tpl_name); ?></td>
                    <td>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin.php?page=routespro-projects&edit='.$r['id']), 'routespro_projects_edit_'.$r['id'] ) ); ?>">Editar</a> |
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin.php?page=routespro-projects&delete='.$r['id']), 'routespro_projects_del_'.$r['id'] ) ); ?>" onclick="return confirm('Remover?')">Remover</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php echo '</div>';
    }
}
