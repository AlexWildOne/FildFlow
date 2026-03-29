<?php
namespace RoutesPro\Admin;

if (!defined('ABSPATH')) exit;

class Categories {
    public static function render(): void {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;
        $table = $wpdb->prefix . 'routespro_categories';

        self::handle_actions($wpdb, $table);

        $edit_id = absint($_GET['edit'] ?? 0);
        $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $edit_id), ARRAY_A) : null;

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY COALESCE(parent_id,0) ASC, sort_order ASC, name ASC", ARRAY_A);
        $rows_by_id = [];
        foreach ($rows as $row) $rows_by_id[(int)$row['id']] = $row;
        $parents = array_values(array_filter($rows, fn($r) => empty($r['parent_id'])));

        echo '<div class="wrap">';
        Branding::render_header('Categorias', 'Gestão completa de categorias e subcategorias para a base comercial e rotas.');

        echo '<div class="routespro-card" style="margin-bottom:18px">';
        echo '<h2 style="margin-top:0">' . ($edit_row ? 'Editar categoria' : 'Nova categoria') . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('routespro_categories_save', 'routespro_categories_nonce');
        echo '<input type="hidden" name="category_action" value="save" />';
        if ($edit_row) {
            echo '<input type="hidden" name="id" value="' . intval($edit_row['id']) . '" />';
        }
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Nome</th><td><input type="text" name="name" class="regular-text" required value="' . esc_attr($edit_row['name'] ?? '') . '" /></td></tr>';
        echo '<tr><th>Categoria pai</th><td><select name="parent_id"><option value="">-- raiz --</option>';
        foreach ($parents as $p) {
            $selected = selected((int)($edit_row['parent_id'] ?? 0), (int)$p['id'], false);
            if ($edit_row && (int)$edit_row['id'] === (int)$p['id']) continue;
            echo '<option value="'.intval($p['id']).'" '.$selected.'>'.esc_html($p['name']).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>Tipo</th><td><input type="text" name="type" class="regular-text" placeholder="horeca ou retalho" value="' . esc_attr($edit_row['type'] ?? '') . '" /></td></tr>';
        echo '<tr><th>Ordem</th><td><input type="number" name="sort_order" class="small-text" value="' . esc_attr($edit_row['sort_order'] ?? 0) . '" /></td></tr>';
        echo '<tr><th>Estado</th><td><label><input type="checkbox" name="is_active" value="1" ' . checked((int)($edit_row['is_active'] ?? 1), 1, false) . ' /> Ativa</label></td></tr>';
        echo '</tbody></table><p>';
        echo '<button class="button button-primary">' . ($edit_row ? 'Guardar alterações' : 'Guardar categoria') . '</button>';
        if ($edit_row) {
            echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=routespro-categories')) . '">Cancelar</a>';
        }
        echo '</p></form>';
        echo '</div>';

        echo '<div class="routespro-card">';
        echo '<h2 style="margin-top:0">Lista</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Nome</th><th>Pai</th><th>Tipo</th><th>Estado</th><th>Atualizada</th><th>Ações</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $parent_name = '';
            if (!empty($r['parent_id']) && isset($rows_by_id[(int)$r['parent_id']])) {
                $parent_name = $rows_by_id[(int)$r['parent_id']]['name'];
            }
            $toggle_action = ((int)$r['is_active'] === 1) ? 'disable' : 'enable';
            $toggle_label = ((int)$r['is_active'] === 1) ? 'Desativar' : 'Ativar';
            $actions = [];
            $actions[] = '<a href="' . esc_url(admin_url('admin.php?page=routespro-categories&edit=' . intval($r['id']))) . '">Editar</a>';
            $actions[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=routespro-categories&category_action='.$toggle_action.'&id='.intval($r['id'])), 'routespro_categories_toggle_' . intval($r['id']))) . '">' . esc_html($toggle_label) . '</a>';
            $actions[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=routespro-categories&category_action=delete&id='.intval($r['id'])), 'routespro_categories_delete_' . intval($r['id']))) . '" onclick="return confirm(\'Apagar esta categoria?\')">Apagar</a>';
            echo '<tr>';
            echo '<td>' . intval($r['id']) . '</td>';
            echo '<td>' . esc_html($r['name']) . '</td>';
            echo '<td>' . esc_html($parent_name) . '</td>';
            echo '<td>' . esc_html((string)$r['type']) . '</td>';
            echo '<td>' . (((int)$r['is_active'] === 1) ? '<span style="color:#15803d;font-weight:600">Ativa</span>' : '<span style="color:#b45309;font-weight:600">Inativa</span>') . '</td>';
            echo '<td>' . esc_html((string)($r['updated_at'] ?? '')) . '</td>';
            echo '<td>' . implode(' | ', $actions) . '</td>';
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="7"><em>Sem categorias.</em></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function handle_actions($wpdb, string $table): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['routespro_categories_nonce']) && wp_verify_nonce($_POST['routespro_categories_nonce'], 'routespro_categories_save')) {
            $id = absint($_POST['id'] ?? 0);
            $name = sanitize_text_field($_POST['name'] ?? '');
            $parent_id = absint($_POST['parent_id'] ?? 0) ?: null;
            $type = sanitize_text_field($_POST['type'] ?? '');
            $sort_order = intval($_POST['sort_order'] ?? 0);
            $is_active = !empty($_POST['is_active']) ? 1 : 0;

            if ($name !== '') {
                $data = [
                    'parent_id' => $parent_id,
                    'name' => $name,
                    'slug' => sanitize_title($name),
                    'type' => $type,
                    'sort_order' => $sort_order,
                    'is_active' => $is_active,
                ];
                if ($id > 0) {
                    $wpdb->update($table, $data, ['id' => $id]);
                    echo '<div class="notice notice-success"><p>Categoria atualizada.</p></div>';
                } else {
                    $wpdb->insert($table, $data);
                    echo '<div class="notice notice-success"><p>Categoria criada.</p></div>';
                }
            }
        }

        $action = sanitize_text_field($_GET['category_action'] ?? '');
        $id = absint($_GET['id'] ?? 0);
        if ($id > 0 && in_array($action, ['enable','disable','delete'], true)) {
            if ($action === 'delete') {
                check_admin_referer('routespro_categories_delete_' . $id);
                $children = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE parent_id=%d", $id));
                if ($children > 0) {
                    echo '<div class="notice notice-error"><p>Não podes apagar uma categoria que ainda tem subcategorias.</p></div>';
                } else {
                    $wpdb->delete($table, ['id' => $id], ['%d']);
                    echo '<div class="notice notice-success"><p>Categoria apagada.</p></div>';
                }
            } else {
                check_admin_referer('routespro_categories_toggle_' . $id);
                $wpdb->update($table, ['is_active' => ($action === 'enable' ? 1 : 0)], ['id' => $id], ['%d'], ['%d']);
                echo '<div class="notice notice-success"><p>Estado da categoria atualizado.</p></div>';
            }
        }
    }
}
