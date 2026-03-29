<?php
namespace RoutesPro\Admin;

class Integrations {
    const OPT_KEY = 'routespro_integrations';

    public static function defaults(): array {
        return [
            'api_enabled' => 1,
            'api_token' => '',
            'api_allow_clients' => 1,
            'api_allow_projects' => 1,
            'api_allow_locations' => 1,
            'api_allow_routes' => 1,
            'api_allow_route_stops' => 1,
            'api_allow_events' => 1,
            'powerbi_enabled' => 0,
            'powerbi_push_url' => '',
            'powerbi_auth_type' => 'bearer',
            'powerbi_token' => '',
            'powerbi_table' => 'routespro_export',
            'gcloud_enabled' => 0,
            'gcloud_push_url' => '',
            'gcloud_auth_header' => '',
            'gcloud_project' => '',
            'gcloud_dataset' => '',
            'gcloud_table' => '',
            'gcloud_service_account_json' => '',
            'azure_enabled' => 0,
            'azure_push_url' => '',
            'azure_auth_header' => '',
            'azure_workspace' => '',
            'azure_dataset' => '',
            'sync_timeout' => 25,
            'batch_size' => 500,
        ];
    }

    public static function get(): array {
        $saved = get_option(self::OPT_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $opts = wp_parse_args($saved, self::defaults());
        if (empty($opts['api_token'])) {
            $opts['api_token'] = wp_generate_password(40, false, false);
            update_option(self::OPT_KEY, $opts);
        }
        return $opts;
    }

    public static function render() {
        if (!current_user_can('routespro_manage')) {
            wp_die('Sem permissões.');
        }

        $message = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['routespro_integrations_nonce']) && wp_verify_nonce($_POST['routespro_integrations_nonce'], 'routespro_integrations_save')) {
            $opts = self::get();
            $opts['api_enabled'] = empty($_POST['api_enabled']) ? 0 : 1;
            $opts['api_token'] = sanitize_text_field($_POST['api_token'] ?? $opts['api_token']);
            if ($opts['api_token'] === '') {
                $opts['api_token'] = wp_generate_password(40, false, false);
            }
            foreach (['clients','projects','locations','routes','route_stops','events'] as $resource) {
                $opts['api_allow_' . $resource] = empty($_POST['api_allow_' . $resource]) ? 0 : 1;
            }
            $opts['powerbi_enabled'] = empty($_POST['powerbi_enabled']) ? 0 : 1;
            $opts['powerbi_push_url'] = esc_url_raw($_POST['powerbi_push_url'] ?? '');
            $opts['powerbi_auth_type'] = sanitize_text_field($_POST['powerbi_auth_type'] ?? 'bearer');
            $opts['powerbi_token'] = sanitize_text_field($_POST['powerbi_token'] ?? '');
            $opts['powerbi_table'] = sanitize_text_field($_POST['powerbi_table'] ?? 'routespro_export');

            $opts['gcloud_enabled'] = empty($_POST['gcloud_enabled']) ? 0 : 1;
            $opts['gcloud_push_url'] = esc_url_raw($_POST['gcloud_push_url'] ?? '');
            $opts['gcloud_auth_header'] = sanitize_text_field($_POST['gcloud_auth_header'] ?? '');
            $opts['gcloud_project'] = sanitize_text_field($_POST['gcloud_project'] ?? '');
            $opts['gcloud_dataset'] = sanitize_text_field($_POST['gcloud_dataset'] ?? '');
            $opts['gcloud_table'] = sanitize_text_field($_POST['gcloud_table'] ?? '');
            $opts['gcloud_service_account_json'] = wp_kses_post((string)($_POST['gcloud_service_account_json'] ?? ''));

            $opts['azure_enabled'] = empty($_POST['azure_enabled']) ? 0 : 1;
            $opts['azure_push_url'] = esc_url_raw($_POST['azure_push_url'] ?? '');
            $opts['azure_auth_header'] = sanitize_text_field($_POST['azure_auth_header'] ?? '');
            $opts['azure_workspace'] = sanitize_text_field($_POST['azure_workspace'] ?? '');
            $opts['azure_dataset'] = sanitize_text_field($_POST['azure_dataset'] ?? '');
            $opts['sync_timeout'] = max(5, min(120, absint($_POST['sync_timeout'] ?? 25)));
            $opts['batch_size'] = max(50, min(5000, absint($_POST['batch_size'] ?? 500)));

            update_option(self::OPT_KEY, $opts);
            $message = 'Integrações guardadas.';
        }

        if (isset($_POST['routespro_regenerate_token']) && check_admin_referer('routespro_integrations_regenerate', 'routespro_integrations_regenerate_nonce')) {
            $opts = self::get();
            $opts['api_token'] = wp_generate_password(40, false, false);
            update_option(self::OPT_KEY, $opts);
            $message = 'Token regenerado.';
        }

        $o = self::get();
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $logs = $wpdb->get_results("SELECT * FROM {$px}integration_logs ORDER BY id DESC LIMIT 50", ARRAY_A) ?: [];
        $base = rest_url('routespro/v1/integrations/export');
        ?>
        <div class="wrap">
          <?php \RoutesPro\Admin\Branding::render_header('Integrações', 'Liga a operação a Power BI, Azure, Google Cloud e CRMs externos, com uma camada neutra de exportação e logs.'); ?>
          <?php if ($message): ?><div class="updated notice"><p><?php echo esc_html($message); ?></p></div><?php endif; ?>
          <style>
            .routespro-int-grid{display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:20px;align-items:start}
            .routespro-int-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 10px 30px rgba(15,23,42,.06);padding:22px;margin-top:18px}
            .routespro-int-card h2{margin-top:0}
            .routespro-int-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;word-break:break-all;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:14px}
            .routespro-int-badges span{display:inline-block;background:#eef2ff;color:#3730a3;padding:4px 10px;border-radius:999px;margin:0 8px 8px 0;font-size:12px;font-weight:600}
            @media (max-width:1180px){.routespro-int-grid{grid-template-columns:1fr}}
          </style>
          <div class="routespro-int-grid"><div>
            <form method="post">
              <?php wp_nonce_field('routespro_integrations_save', 'routespro_integrations_nonce'); ?>
              <div class="routespro-int-card">
                <h2>API de Integração</h2>
                <p>Camada neutra para Power BI, Azure Data Factory, BigQuery, CRMs e integrações próprias.</p>
                <table class="form-table"><tbody>
                  <tr><th>Ativa</th><td><label><input type="checkbox" name="api_enabled" value="1" <?php checked(!empty($o['api_enabled'])); ?>> Expor endpoints externos</label></td></tr>
                  <tr><th>Token</th><td><input type="text" class="regular-text code" name="api_token" value="<?php echo esc_attr($o['api_token']); ?>"> <p class="description">Enviar em <code>X-RoutesPro-Token</code> ou <code>Authorization: Bearer TOKEN</code>.</p></td></tr>
                  <tr><th>Recursos</th><td>
                    <label><input type="checkbox" name="api_allow_clients" value="1" <?php checked(!empty($o['api_allow_clients'])); ?>> Clientes</label><br>
                    <label><input type="checkbox" name="api_allow_projects" value="1" <?php checked(!empty($o['api_allow_projects'])); ?>> Campanhas</label><br>
                    <label><input type="checkbox" name="api_allow_locations" value="1" <?php checked(!empty($o['api_allow_locations'])); ?>> PDVs</label><br>
                    <label><input type="checkbox" name="api_allow_routes" value="1" <?php checked(!empty($o['api_allow_routes'])); ?>> Rotas</label><br>
                    <label><input type="checkbox" name="api_allow_route_stops" value="1" <?php checked(!empty($o['api_allow_route_stops'])); ?>> Reporte por paragem</label><br>
                    <label><input type="checkbox" name="api_allow_events" value="1" <?php checked(!empty($o['api_allow_events'])); ?>> Eventos</label>
                  </td></tr>
                  <tr><th>Timeout</th><td><input type="number" min="5" max="120" name="sync_timeout" value="<?php echo esc_attr((string)$o['sync_timeout']); ?>"> segundos</td></tr>
                  <tr><th>Lote</th><td><input type="number" min="50" max="5000" name="batch_size" value="<?php echo esc_attr((string)$o['batch_size']); ?>"> linhas por sync</td></tr>
                </tbody></table>
                <p><button class="button button-primary">Guardar integrações</button></p>
              </div>

              <div class="routespro-int-card">
                <h2>Power BI</h2>
                <table class="form-table"><tbody>
                  <tr><th>Ativa</th><td><label><input type="checkbox" name="powerbi_enabled" value="1" <?php checked(!empty($o['powerbi_enabled'])); ?>> Ativar destino Power BI</label></td></tr>
                  <tr><th>Push URL</th><td><input type="url" class="regular-text" name="powerbi_push_url" placeholder="https://api.powerbi.com/..." value="<?php echo esc_attr($o['powerbi_push_url']); ?>"></td></tr>
                  <tr><th>Autenticação</th><td><select name="powerbi_auth_type"><option value="bearer" <?php selected($o['powerbi_auth_type'], 'bearer'); ?>>Bearer</option><option value="none" <?php selected($o['powerbi_auth_type'], 'none'); ?>>Nenhuma</option></select></td></tr>
                  <tr><th>Token</th><td><input type="text" class="regular-text code" name="powerbi_token" value="<?php echo esc_attr($o['powerbi_token']); ?>"></td></tr>
                  <tr><th>Tabela lógica</th><td><input type="text" class="regular-text" name="powerbi_table" value="<?php echo esc_attr($o['powerbi_table']); ?>"></td></tr>
                </tbody></table>
              </div>

              <div class="routespro-int-card">
                <h2>Google Cloud</h2>
                <p>Preparado para pipelines de ingestão, Cloud Run, Functions ou proxy para BigQuery.</p>
                <table class="form-table"><tbody>
                  <tr><th>Ativa</th><td><label><input type="checkbox" name="gcloud_enabled" value="1" <?php checked(!empty($o['gcloud_enabled'])); ?>> Ativar destino Google Cloud</label></td></tr>
                  <tr><th>Endpoint</th><td><input type="url" class="regular-text" name="gcloud_push_url" placeholder="https://...run.app/..." value="<?php echo esc_attr($o['gcloud_push_url']); ?>"></td></tr>
                  <tr><th>Header auth</th><td><input type="text" class="regular-text code" name="gcloud_auth_header" placeholder="Authorization: Bearer XXX" value="<?php echo esc_attr($o['gcloud_auth_header']); ?>"></td></tr>
                  <tr><th>Projeto</th><td><input type="text" class="regular-text" name="gcloud_project" value="<?php echo esc_attr($o['gcloud_project']); ?>"></td></tr>
                  <tr><th>Dataset</th><td><input type="text" class="regular-text" name="gcloud_dataset" value="<?php echo esc_attr($o['gcloud_dataset']); ?>"></td></tr>
                  <tr><th>Tabela</th><td><input type="text" class="regular-text" name="gcloud_table" value="<?php echo esc_attr($o['gcloud_table']); ?>"></td></tr>
                  <tr><th>JSON service account</th><td><textarea name="gcloud_service_account_json" class="large-text code" rows="6"><?php echo esc_textarea((string)$o['gcloud_service_account_json']); ?></textarea></td></tr>
                </tbody></table>
              </div>

              <div class="routespro-int-card">
                <h2>Microsoft Azure</h2>
                <p>Pensado para Data Factory, Logic Apps, Functions ou endpoints próprios.</p>
                <table class="form-table"><tbody>
                  <tr><th>Ativa</th><td><label><input type="checkbox" name="azure_enabled" value="1" <?php checked(!empty($o['azure_enabled'])); ?>> Ativar destino Azure</label></td></tr>
                  <tr><th>Endpoint</th><td><input type="url" class="regular-text" name="azure_push_url" placeholder="https://prod-xx.westeurope.logic.azure.com/..." value="<?php echo esc_attr($o['azure_push_url']); ?>"></td></tr>
                  <tr><th>Header auth</th><td><input type="text" class="regular-text code" name="azure_auth_header" placeholder="x-functions-key: XXX" value="<?php echo esc_attr($o['azure_auth_header']); ?>"></td></tr>
                  <tr><th>Workspace</th><td><input type="text" class="regular-text" name="azure_workspace" value="<?php echo esc_attr($o['azure_workspace']); ?>"></td></tr>
                  <tr><th>Dataset</th><td><input type="text" class="regular-text" name="azure_dataset" value="<?php echo esc_attr($o['azure_dataset']); ?>"></td></tr>
                </tbody></table>
              </div>
            </form>
          </div><aside>
            <div class="routespro-int-card">
              <h2>Endpoints</h2>
              <div class="routespro-int-badges"><span>/schema</span><span>/export</span><span>/push</span><span>/test</span></div>
              <p>Exemplo rápido para Power BI, Azure Data Factory, BigQuery proxy ou CRM externo:</p>
              <div class="routespro-int-mono"><?php echo esc_html($base . '?resource=locations&client_id=0&project_id=0&limit=500'); ?></div>
              <p style="margin-top:12px">Headers:</p>
              <div class="routespro-int-mono">X-RoutesPro-Token: <?php echo esc_html($o['api_token']); ?></div>
              <form method="post" style="margin-top:14px">
                <?php wp_nonce_field('routespro_integrations_regenerate', 'routespro_integrations_regenerate_nonce'); ?>
                <input type="hidden" name="routespro_regenerate_token" value="1">
                <button class="button">Regenerar token</button>
              </form>
            </div>
            <div class="routespro-int-card">
              <h2>Modelo de dados exportável</h2>
              <ul style="margin-left:18px;list-style:disc">
                <li>Clientes</li>
                <li>Campanhas</li>
                <li>PDVs</li>
                <li>Rotas</li>
                <li>Paragens com reporte</li>
                <li>Eventos operacionais</li>
              </ul>
              <p class="description">A base foi desenhada para alimentares Power BI e relatórios externos sem acoplar o WordPress a um fornecedor único.</p>
            </div>
            <div class="routespro-int-card">
              <h2>Logs recentes</h2>
              <div style="max-height:500px;overflow:auto">
                <table class="widefat striped"><thead><tr><th>Quando</th><th>Destino</th><th>Ação</th><th>Estado</th></tr></thead><tbody>
                  <?php if (!$logs): ?>
                    <tr><td colspan="4">Sem logs ainda.</td></tr>
                  <?php else: foreach ($logs as $log): ?>
                    <tr>
                      <td><?php echo esc_html((string)$log['created_at']); ?></td>
                      <td><?php echo esc_html((string)$log['connector']); ?></td>
                      <td><?php echo esc_html((string)$log['action']); ?></td>
                      <td><?php echo esc_html((string)$log['status']); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody></table>
              </div>
            </div>
          </aside></div>
        </div>
        <?php
    }
}
