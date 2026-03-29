<?php
namespace RoutesPro\Admin;

class Settings {
    const OPT_KEY = 'routespro_settings';

    /**
     * Defaults globais das opções
     */
    private static function defaults(): array {
        return [
            // Otimizador
            'optimizer_url'         => '',
            'optimizer_api_key'     => '',

            // Mapas
            'maps_provider'         => 'leaflet',  // leaflet | google | azure
            'google_maps_key'       => '',
            'azure_maps_key'        => '',

            // IA
            'ai_provider'           => 'none',     // none | google | azure | openai | copilot
            'google_ai_key'         => '',
            'azure_openai_endpoint' => '',
            'azure_openai_deployment' => '',
            'azure_openai_key'      => '',
            'openai_api_key'        => '',
            'openai_base_url'       => '',
            'openai_model'          => 'gpt-4o-mini',
            'copilot_webhook_url'   => '',
            'copilot_auth_header'   => '',
        ];
    }

    /**
     * Recupera todas as opções (com defaults) ou uma chave específica.
     */
    public static function get($key = null, $default = null) {
        $saved = get_option(self::OPT_KEY, []);
        $opts  = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
        if ($key === null) return $opts;
        return array_key_exists($key, $opts) ? $opts[$key] : $default;
    }

    /**
     * Render da página de Settings (BO)
     */
    public static function render() {
        if (!current_user_can('routespro_manage')) return;

        if (!empty($_POST['routespro_settings_categories_nonce']) && wp_verify_nonce($_POST['routespro_settings_categories_nonce'], 'routespro_settings_categories')) {
            global $wpdb; $px = $wpdb->prefix . 'routespro_';
            $cat_name = sanitize_text_field($_POST['settings_category_name'] ?? '');
            $cat_parent = absint($_POST['settings_category_parent_id'] ?? 0) ?: null;
            $cat_type = sanitize_text_field($_POST['settings_category_type'] ?? '');
            if ($cat_name !== '') {
                $wpdb->insert($px.'categories', [
                    'parent_id' => $cat_parent,
                    'name' => $cat_name,
                    'slug' => sanitize_title($cat_name),
                    'type' => $cat_type,
                    'is_active' => 1,
                ]);
                echo '<div class="updated notice"><p>Categoria guardada em Settings.</p></div>';
            }
        }

        if (!empty($_POST['routespro_settings_nonce']) && wp_verify_nonce($_POST['routespro_settings_nonce'], 'routespro_settings')) {

            // Whitelists
            $maps_allowed = ['leaflet','google','azure'];
            $ai_allowed   = ['none','google','azure','openai','copilot'];

            // Monta opções saneadas
            $opts = [
                // Otimizador
                'optimizer_url'         => esc_url_raw($_POST['optimizer_url'] ?? ''),
                'optimizer_api_key'     => sanitize_text_field($_POST['optimizer_api_key'] ?? ''),

                // Mapas
                'maps_provider'         => in_array(($_POST['maps_provider'] ?? 'leaflet'), $maps_allowed, true)
                                            ? $_POST['maps_provider'] : 'leaflet',
                'google_maps_key'       => sanitize_text_field($_POST['google_maps_key'] ?? ''),
                'azure_maps_key'        => sanitize_text_field($_POST['azure_maps_key'] ?? ''),

                // IA
                'ai_provider'           => in_array(($_POST['ai_provider'] ?? 'none'), $ai_allowed, true)
                                            ? $_POST['ai_provider'] : 'none',
                'google_ai_key'         => sanitize_text_field($_POST['google_ai_key'] ?? ''),

                'azure_openai_endpoint' => esc_url_raw($_POST['azure_openai_endpoint'] ?? ''),
                'azure_openai_deployment' => sanitize_text_field($_POST['azure_openai_deployment'] ?? ''),
                'azure_openai_key'      => sanitize_text_field($_POST['azure_openai_key'] ?? ''),

                'openai_api_key'        => sanitize_text_field($_POST['openai_api_key'] ?? ''),
                'openai_base_url'       => esc_url_raw($_POST['openai_base_url'] ?? ''),
                'openai_model'          => sanitize_text_field($_POST['openai_model'] ?? 'gpt-4o-mini'),

                'copilot_webhook_url'   => esc_url_raw($_POST['copilot_webhook_url'] ?? ''),
                'copilot_auth_header'   => sanitize_text_field($_POST['copilot_auth_header'] ?? ''),
            ];

            // Persiste (mantém quaisquer chaves antigas que não estejam no form)
            $merged = wp_parse_args($opts, self::get()); // preserva valores existentes não presentes no post
            update_option(self::OPT_KEY, $merged);

            echo '<div class="updated notice"><p>Settings guardadas.</p></div>';
        }

        $o = self::get();
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $settings_categories = $wpdb->get_results("SELECT id,parent_id,name,type,is_active FROM {$px}categories ORDER BY COALESCE(parent_id,0), sort_order, name", ARRAY_A);
        $settings_category_roots = array_values(array_filter($settings_categories, fn($r) => empty($r['parent_id'])));
        ?>
        <div class="wrap">
          <?php \RoutesPro\Admin\Branding::render_header('Settings', 'Configura integrações, mapas e categorias da operação num único ecrã.'); ?>
          <style>
            .routespro-settings-grid{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:20px;align-items:start}
            .routespro-settings-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 10px 30px rgba(15,23,42,.06);padding:22px;margin-top:16px}
            .routespro-settings-card h2{margin-top:0}
            @media (max-width: 1180px){.routespro-settings-grid{grid-template-columns:1fr}}
          </style>
          <div class="routespro-settings-grid"><div>
          <form method="post">
            <?php wp_nonce_field('routespro_settings','routespro_settings_nonce'); ?>

            <h2 style="margin-top:1em">Otimizador (VRP/TSP)</h2>
            <table class="form-table">
              <tr>
                <th scope="row"><label for="optimizer_url">Optimizer URL</label></th>
                <td><input id="optimizer_url" name="optimizer_url" class="regular-text" placeholder="https://teu-servico/vrp" value="<?php echo esc_attr($o['optimizer_url']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="optimizer_api_key">Optimizer API Key</label></th>
                <td><input id="optimizer_api_key" name="optimizer_api_key" class="regular-text" value="<?php echo esc_attr($o['optimizer_api_key']); ?>"/></td>
              </tr>
            </table>

            <h2 style="margin-top:1em">Mapas</h2>
            <table class="form-table">
              <tr>
                <th scope="row"><label for="maps_provider">Fornecedor</label></th>
                <td>
                  <select id="maps_provider" name="maps_provider">
                    <option value="leaflet" <?php selected($o['maps_provider'],'leaflet'); ?>>Leaflet (OSM)</option>
                    <option value="google"  <?php selected($o['maps_provider'],'google'); ?>>Google Maps</option>
                    <option value="azure"   <?php selected($o['maps_provider'],'azure'); ?>>Microsoft Azure Maps</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="google_maps_key">Google Maps Key</label></th>
                <td><input id="google_maps_key" name="google_maps_key" class="regular-text" value="<?php echo esc_attr($o['google_maps_key']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="azure_maps_key">Azure Maps Key</label></th>
                <td><input id="azure_maps_key" name="azure_maps_key" class="regular-text" value="<?php echo esc_attr($o['azure_maps_key']); ?>"/></td>
              </tr>
            </table>

            <h2 style="margin-top:1em">IA</h2>
            <table class="form-table">
              <tr>
                <th scope="row"><label for="ai_provider">Fornecedor</label></th>
                <td>
                  <select id="ai_provider" name="ai_provider">
                    <option value="none"   <?php selected($o['ai_provider'],'none'); ?>>Nenhum</option>
                    <option value="google" <?php selected($o['ai_provider'],'google'); ?>>Google (Gemini)</option>
                    <option value="azure"  <?php selected($o['ai_provider'],'azure'); ?>>Microsoft (Azure OpenAI)</option>
                    <option value="openai" <?php selected($o['ai_provider'],'openai'); ?>>OpenAI (ChatGPT)</option>
                    <option value="copilot"<?php selected($o['ai_provider'],'copilot'); ?>>Copilot (Webhook)</option>
                  </select>
                </td>
              </tr>

              <tr>
                <th scope="row"><label for="google_ai_key">Google AI Key</label></th>
                <td><input id="google_ai_key" name="google_ai_key" class="regular-text" value="<?php echo esc_attr($o['google_ai_key']); ?>"/></td>
              </tr>

              <tr>
                <th scope="row"><label for="azure_openai_endpoint">Azure OpenAI Endpoint</label></th>
                <td><input id="azure_openai_endpoint" name="azure_openai_endpoint" class="regular-text" placeholder="https://xxx.openai.azure.com/" value="<?php echo esc_attr($o['azure_openai_endpoint']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="azure_openai_deployment">Azure OpenAI Deployment</label></th>
                <td><input id="azure_openai_deployment" name="azure_openai_deployment" class="regular-text" placeholder="ex: gpt-4o-mini" value="<?php echo esc_attr($o['azure_openai_deployment']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="azure_openai_key">Azure OpenAI Key</label></th>
                <td><input id="azure_openai_key" name="azure_openai_key" class="regular-text" value="<?php echo esc_attr($o['azure_openai_key']); ?>"/></td>
              </tr>

              <tr>
                <th scope="row"><label for="openai_api_key">OpenAI API Key</label></th>
                <td><input id="openai_api_key" name="openai_api_key" class="regular-text" value="<?php echo esc_attr($o['openai_api_key']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="openai_base_url">OpenAI Base URL (opcional)</label></th>
                <td><input id="openai_base_url" name="openai_base_url" class="regular-text" placeholder="https://api.openai.com" value="<?php echo esc_attr($o['openai_base_url']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="openai_model">OpenAI Modelo</label></th>
                <td><input id="openai_model" name="openai_model" class="regular-text" placeholder="gpt-4o-mini" value="<?php echo esc_attr($o['openai_model']); ?>"/></td>
              </tr>

              <tr>
                <th scope="row"><label for="copilot_webhook_url">Copilot Webhook URL</label></th>
                <td><input id="copilot_webhook_url" name="copilot_webhook_url" class="regular-text" placeholder="https://..." value="<?php echo esc_attr($o['copilot_webhook_url']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="copilot_auth_header">Copilot Auth Header (opcional)</label></th>
                <td><input id="copilot_auth_header" name="copilot_auth_header" class="regular-text" placeholder="Authorization: Bearer XXX" value="<?php echo esc_attr($o['copilot_auth_header']); ?>"/></td>
              </tr>
            </table>

            <p><button class="button button-primary">Guardar</button></p>
          </form>
          </div>
          <aside>
            <div class="routespro-settings-card" id="routespro-settings-categories">
              <h2>Categorias rápidas</h2>
              <p style="color:#64748b">Cria novas categorias e subcategorias sem sair de Settings.</p>
              <form method="post">
                <?php wp_nonce_field('routespro_settings_categories','routespro_settings_categories_nonce'); ?>
                <table class="form-table"><tbody>
                  <tr><th>Nome</th><td><input type="text" name="settings_category_name" class="regular-text" required></td></tr>
                  <tr><th>Categoria pai</th><td><select name="settings_category_parent_id"><option value="">-- raiz --</option><?php foreach($settings_category_roots as $root): ?><option value="<?php echo intval($root['id']); ?>"><?php echo esc_html($root['name']); ?></option><?php endforeach; ?></select></td></tr>
                  <tr><th>Tipo</th><td><input type="text" name="settings_category_type" class="regular-text" placeholder="horeca ou retalho"></td></tr>
                </tbody></table>
                <p><button class="button button-secondary">Adicionar categoria</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=routespro-categories')); ?>">Abrir gestão completa</a></p>
              </form>
            </div>
            <div class="routespro-settings-card">
              <h2>Resumo de categorias</h2>
              <div style="max-height:420px;overflow:auto">
                <table class="widefat striped"><thead><tr><th>Nome</th><th>Pai</th><th>Tipo</th></tr></thead><tbody>
                <?php foreach($settings_categories as $row): $parent=''; foreach($settings_category_roots as $root){ if((int)$root['id']===(int)$row['parent_id']){$parent=$root['name']; break;} } ?>
                  <tr><td><?php echo esc_html($row['name']); ?></td><td><?php echo esc_html($parent); ?></td><td><?php echo esc_html((string)$row['type']); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
              </div>
            </div>
          </aside></div>
        </div>
        <?php
    }
}
