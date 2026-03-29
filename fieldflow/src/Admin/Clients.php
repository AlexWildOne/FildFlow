<?php
namespace RoutesPro\Admin;

class Clients {
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
        $px    = $wpdb->prefix . 'routespro_';
        $table = $px . 'clients';
        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);

        if (!empty($_POST['routespro_clients_nonce']) && wp_verify_nonce($_POST['routespro_clients_nonce'],'routespro_clients')) {
            $id   = absint($_POST['id'] ?? 0);
            $name = sanitize_text_field($_POST['name'] ?? '');
            $tax  = sanitize_text_field($_POST['taxid'] ?? '');
            $mail = sanitize_email($_POST['email'] ?? '');
            $phone= sanitize_text_field($_POST['phone'] ?? '');
            $associated_user_ids = array_values(array_filter(array_map('absint', (array)($_POST['associated_user_ids'] ?? []))));

            if (!$name) {
                echo '<div class="error notice"><p>O nome é obrigatório.</p></div>';
            } else {
                $existing_meta = [];
                if ($id) {
                    $existing_meta_raw = $wpdb->get_var($wpdb->prepare("SELECT meta_json FROM {$table} WHERE id=%d", $id));
                    $existing_meta = self::decode_meta((string)$existing_meta_raw);
                }
                $existing_meta['associated_user_ids'] = $associated_user_ids;
                $existing_meta['user_ids'] = $associated_user_ids;

                $data = [
                    'name'  => $name,
                    'taxid' => $tax,
                    'email' => $mail ?: '',
                    'phone' => $phone ?: '',
                    'meta_json' => wp_json_encode($existing_meta, JSON_UNESCAPED_UNICODE),
                ];
                $formats = ['%s','%s','%s','%s','%s'];

                if ($id) {
                    $ok = $wpdb->update($table, $data, ['id'=>$id], $formats, ['%d']);
                } else {
                    $ok = $wpdb->insert($table, $data, $formats);
                    if ($ok) { $id = (int)$wpdb->insert_id; }
                }

                if ($ok === false) {
                    echo '<div class="error notice"><p>Falha ao guardar: ' . esc_html($wpdb->last_error ?: 'DB erro') . '</p></div>';
                } else {
                    echo '<div class="updated notice"><p>Guardado.</p></div>';
                }
            }
        }

        if (!empty($_GET['delete'])) {
            $del = absint($_GET['delete']);
            if ($del) {
                check_admin_referer('routespro_clients_del_'.$del);
                $ok = $wpdb->delete($table, ['id'=>$del], ['%d']);
                if ($ok === false) {
                    echo '<div class="error notice"><p>Falha ao remover: ' . esc_html($wpdb->last_error ?: 'DB erro') . '</p></div>';
                } else {
                    echo '<div class="updated notice"><p>Removido.</p></div>';
                }
            }
        }

        $edit = null;
        if (!empty($_GET['edit'])) {
            $id = absint($_GET['edit']);
            if ($id) {
                $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
            }
        }
        $edit_meta = self::decode_meta((string)($edit['meta_json'] ?? ''));
        $selected_users = self::extract_user_ids($edit_meta);

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC, id DESC LIMIT 500", ARRAY_A);

        echo '<div class="wrap"><h1>Clientes</h1>';
        ?>
        <h2><?php echo $edit ? 'Editar Cliente' : 'Novo Cliente'; ?></h2>
        <form method="post">
            <?php wp_nonce_field('routespro_clients','routespro_clients_nonce'); ?>
            <input type="hidden" name="id" value="<?php echo esc_attr($edit['id'] ?? 0); ?>"/>
            <table class="form-table">
              <tr>
                <th><label for="rp-name">Nome</label></th>
                <td><input id="rp-name" name="name" class="regular-text" required value="<?php echo esc_attr($edit['name'] ?? ''); ?>"/></td>
              </tr>
              <tr>
                <th><label for="rp-taxid">NIF</label></th>
                <td><input id="rp-taxid" name="taxid" class="regular-text" value="<?php echo esc_attr($edit['taxid'] ?? ''); ?>"/></td>
              </tr>
              <tr>
                <th><label for="rp-email">Email</label></th>
                <td><input id="rp-email" type="email" name="email" class="regular-text" value="<?php echo esc_attr($edit['email'] ?? ''); ?>"/></td>
              </tr>
              <tr>
                <th><label for="rp-phone">Telefone</label></th>
                <td><input id="rp-phone" name="phone" class="regular-text" value="<?php echo esc_attr($edit['phone'] ?? ''); ?>"/></td>
              </tr>
              <tr>
                <th><label for="rp-associated-users">Users associados</label></th>
                <td>
                  <select id="rp-associated-users" name="associated_user_ids[]" multiple size="10" style="min-width:320px;max-width:520px">
                    <?php foreach ($users as $user): ?>
                      <option value="<?php echo intval($user->ID); ?>" <?php selected(in_array((int)$user->ID, $selected_users, true)); ?>>
                        <?php echo esc_html(($user->display_name ?: $user->user_login) . ($user->user_email ? ' • ' . $user->user_email : '')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <p class="description">Podes associar vários utilizadores ao mesmo cliente. Estes users passam a ver o cliente no front e nos filtros do portal.</p>
                </td>
              </tr>
            </table>
            <p><button class="button button-primary">Guardar</button></p>
        </form>

        <h2 style="margin-top:2em">Lista</h2>
        <table class="widefat striped">
          <thead>
            <tr>
              <th style="width:70px">ID</th>
              <th>Nome</th>
              <th>NIF</th>
              <th>Email</th>
              <th>Telefone</th>
              <th>Users associados</th>
              <th style="width:160px"></th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): foreach($rows as $r): ?>
            <?php $meta = self::decode_meta((string)($r['meta_json'] ?? '')); $user_ids = self::extract_user_ids($meta); ?>
            <tr>
              <td><?php echo intval($r['id']); ?></td>
              <td><?php echo esc_html($r['name']); ?></td>
              <td><?php echo esc_html($r['taxid']); ?></td>
              <td><?php echo esc_html($r['email']); ?></td>
              <td><?php echo esc_html($r['phone']); ?></td>
              <td><?php echo esc_html($user_ids ? implode(', ', array_map('strval', $user_ids)) : '—'); ?></td>
              <td>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin.php?page=routespro-clients&edit='.$r['id']), 'routespro_clients_edit_'.$r['id'] ) ); ?>">Editar</a> |
                <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin.php?page=routespro-clients&delete='.$r['id']), 'routespro_clients_del_'.$r['id'] ) ); ?>" onclick="return confirm('Remover este cliente?')">Remover</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7"><em>Sem clientes.</em></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <?php
        echo '</div>';
    }
}
