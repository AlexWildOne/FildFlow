<?php
namespace RoutesPro\Admin;

class Emails {
    const OPT_KEY = 'routespro_emails';

    private static function defaults() {
        return [
            'on_completed'       => 1,
            'on_updated'         => 1,
            'to_client'          => 1,
            'to_collaborator'    => 1,
            'extra_emails'       => '',
            'subject_completed'  => '[RoutesPro] Rota #{route_id} concluída ({date})',
            'body_completed'     => "Olá,\n\nA rota #{route_id} do cliente {client_name} foi CONCLUÍDA em {date}.\nResponsável: {user_name}.\nProjeto: {project_name}\nTotal de paragens: {stops}.\n\n{stops_list}\n\nVer rota: {route_url}\n\nCumprimentos,\nRoutesPro",
            'subject_updated'    => '[RoutesPro] Rota #{route_id} atualizada ({date})',
            'body_updated'       => "Olá,\n\nA rota #{route_id} do cliente {client_name} foi ATUALIZADA em {date}.\nResponsável: {user_name}.\nProjeto: {project_name}\nAlterações: {changes}.\n\n{stops_list}\n\nVer rota: {route_url}\n\nCumprimentos,\nRoutesPro",
            'project_templates'  => [],
            'from_name'          => 'RoutesPro',
            'from_email'         => '',
            'reply_to'           => '',
            'brand_primary'      => '#111827',
            'button_label'       => 'Abrir rota',
            'footer_text'        => 'Notificação automática gerada pelo RoutesPro.',
            'send_as_html'       => 1,
        ];
    }

    public static function get() {
        $saved = get_option(self::OPT_KEY, []);
        $opts  = array_merge(self::defaults(), is_array($saved) ? $saved : []);
        if (empty($opts['project_templates']) || !is_array($opts['project_templates'])) {
            $opts['project_templates'] = [];
        }
        return $opts;
    }

    public static function template_tokens(): array {
        return [
            '{route_id}'     => 'ID da rota',
            '{date}'         => 'Data da rota',
            '{client_name}'  => 'Nome do cliente',
            '{project_name}' => 'Nome do projeto ou campanha',
            '{user_name}'    => 'Responsável principal',
            '{user_email}'   => 'Email do responsável principal',
            '{client_email}' => 'Email do cliente',
            '{stops}'        => 'Número total de paragens',
            '{changes}'      => 'Resumo da alteração',
            '{route_status}' => 'Estado atual da rota',
            '{route_url}'    => 'Link da rota no backoffice',
            '{stops_list}'   => 'Lista formatada de paragens',
        ];
    }

    public static function get_project_template(int $project_id, array $opts = []): array {
        $opts = $opts ?: self::get();
        $tpl = [
            'subject_completed' => (string)($opts['subject_completed'] ?? ''),
            'body_completed'    => (string)($opts['body_completed'] ?? ''),
            'subject_updated'   => (string)($opts['subject_updated'] ?? ''),
            'body_updated'      => (string)($opts['body_updated'] ?? ''),
        ];
        if ($project_id && !empty($opts['project_templates'][$project_id]) && is_array($opts['project_templates'][$project_id])) {
            $ov = $opts['project_templates'][$project_id];
            foreach ($tpl as $key => $value) {
                if (!empty($ov[$key])) $tpl[$key] = (string) $ov[$key];
            }
        }
        return $tpl;
    }

    public static function apply_placeholders(string $content, array $context): string {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace[$key] = is_scalar($value) ? (string) $value : '';
        }
        return strtr($content, $replace);
    }

    public static function format_body_html(string $body): string {
        $body = trim((string) $body);
        if ($body === '') return '';
        if (preg_match('/<\/?(?:p|div|table|ul|ol|li|br|strong|em|a|h[1-6])\b/i', $body)) {
            return wp_kses_post($body);
        }
        return wpautop(esc_html($body));
    }

    public static function render_message_html(string $subject, string $body, array $opts = []): string {
        $primary = sanitize_hex_color($opts['brand_primary'] ?? '') ?: '#111827';
        $footer  = trim((string)($opts['footer_text'] ?? ''));
        $button  = trim((string)($opts['button_label'] ?? 'Abrir rota'));
        $routeUrl = esc_url((string)($opts['route_url'] ?? ''));
        $logoUrl = defined('ROUTESPRO_URL') ? ROUTESPRO_URL . 'assets/logo-twt.png' : '';
        $bodyHtml = self::format_body_html($body);
        $buttonHtml = '';
        if ($routeUrl !== '') {
            $buttonHtml = '<p style="margin:24px 0 8px"><a href="'.$routeUrl.'" style="display:inline-block;background:'.$primary.';color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600">'.esc_html($button).'</a></p>';
        }
        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;color:#111827">'
            .'<div style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:20px;overflow:hidden;box-shadow:0 10px 30px rgba(17,24,39,.08)">'
            .'<div style="padding:20px 24px;background:linear-gradient(135deg,'.$primary.' 0%, #374151 100%);color:#ffffff">'
            .($logoUrl ? '<img src="'.esc_url($logoUrl).'" alt="RoutesPro" style="height:34px;max-width:220px;display:block;margin:0 0 14px">' : '')
            .'<div style="font-size:20px;font-weight:700;line-height:1.3">'.esc_html($subject).'</div>'
            .'</div>'
            .'<div style="padding:24px">'.$bodyHtml.$buttonHtml.'</div>'
            .'<div style="padding:16px 24px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px">'.esc_html($footer).'</div>'
            .'</div></body></html>';
    }

    private static function count_overrides(array $opts): int {
        $n = 0;
        foreach ((array)($opts['project_templates'] ?? []) as $row) {
            if (is_array($row) && array_filter($row)) $n++;
        }
        return $n;
    }

    private static function build_demo_context(int $client_id = 0, int $project_id = 0): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $client_name = $client_id ? (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$px}clients WHERE id=%d", $client_id)) : 'Cliente Demo';
        $client_email = $client_id ? (string) $wpdb->get_var($wpdb->prepare("SELECT email FROM {$px}clients WHERE id=%d", $client_id)) : 'cliente@example.com';
        $project_name = $project_id ? (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$px}projects WHERE id=%d", $project_id)) : 'Campanha Demo';
        $user = wp_get_current_user();
        return [
            '{route_id}'     => '123',
            '{date}'         => date_i18n('Y-m-d'),
            '{client_name}'  => $client_name ?: 'Cliente Demo',
            '{project_name}' => $project_name ?: 'Campanha Demo',
            '{user_name}'    => $user && $user->exists() ? (string) $user->display_name : 'Utilizador Demo',
            '{user_email}'   => $user && $user->exists() ? (string) $user->user_email : 'user@example.com',
            '{client_email}' => $client_email ?: 'cliente@example.com',
            '{stops}'        => '5',
            '{changes}'      => 'status: draft para planned, ordem otimizada, owner revisto',
            '{route_status}' => 'planned',
            '{route_url}'    => admin_url('admin.php?page=routespro-routes'),
            '{stops_list}'   => '<ul style="margin:0;padding-left:18px"><li>Pingo Doce Alvalade, Check-in 09:00</li><li>Continente Roma, Check-in 10:10</li><li>Auchan Campo Grande, Check-in 11:35</li><li>Intermarché Lumiar, Check-in 14:15</li><li>Minipreço Telheiras, Check-in 16:00</li></ul>',
        ];
    }

    public static function render(){
        if (!current_user_can('routespro_manage')) return;

        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';

        $o = self::get();
        $clients     = $wpdb->get_results("SELECT id,name,email FROM {$px}clients ORDER BY name ASC", ARRAY_A);
        $sel_client  = absint($_POST['preview_client_id'] ?? ($_GET['preview_client_id'] ?? 0));
        $projects    = $sel_client ? $wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$px}projects WHERE client_id=%d ORDER BY name ASC", $sel_client), ARRAY_A) : [];
        $sel_project = absint($_POST['preview_project_id'] ?? ($_GET['preview_project_id'] ?? 0));
        $preview_type = sanitize_key($_POST['preview_type'] ?? ($_GET['preview_type'] ?? 'updated'));
        if (!in_array($preview_type, ['updated','completed'], true)) $preview_type = 'updated';

        if (!empty($_POST['routespro_emails_nonce']) && wp_verify_nonce($_POST['routespro_emails_nonce'],'routespro_emails')) {
            if (isset($_POST['save_global'])) {
                $o['on_completed']    = isset($_POST['on_completed']) ? 1 : 0;
                $o['on_updated']      = isset($_POST['on_updated']) ? 1 : 0;
                $o['to_client']       = isset($_POST['to_client']) ? 1 : 0;
                $o['to_collaborator'] = isset($_POST['to_collaborator']) ? 1 : 0;
                $o['extra_emails']    = sanitize_text_field($_POST['extra_emails'] ?? '');
                $o['subject_completed'] = sanitize_text_field($_POST['subject_completed'] ?? '');
                $o['body_completed']    = wp_kses_post(str_replace(["\r\n","\r"], "\n", $_POST['body_completed'] ?? ''));
                $o['subject_updated']   = sanitize_text_field($_POST['subject_updated'] ?? '');
                $o['body_updated']      = wp_kses_post(str_replace(["\r\n","\r"], "\n", $_POST['body_updated'] ?? ''));
                $o['from_name']         = sanitize_text_field($_POST['from_name'] ?? '');
                $o['from_email']        = sanitize_email($_POST['from_email'] ?? '');
                $o['reply_to']          = sanitize_email($_POST['reply_to'] ?? '');
                $o['brand_primary']     = sanitize_hex_color($_POST['brand_primary'] ?? '') ?: '#111827';
                $o['button_label']      = sanitize_text_field($_POST['button_label'] ?? 'Abrir rota');
                $o['footer_text']       = sanitize_text_field($_POST['footer_text'] ?? '');
                $o['send_as_html']      = isset($_POST['send_as_html']) ? 1 : 0;

                update_option(self::OPT_KEY, $o);
                echo '<div class="updated notice"><p>Configurações globais guardadas.</p></div>';
            }

            if (isset($_POST['save_project_tpl']) && $sel_project) {
                $pt = $o['project_templates'];
                $pt[$sel_project] = [
                    'subject_completed' => sanitize_text_field($_POST['project_subject_completed'] ?? ''),
                    'body_completed'    => wp_kses_post(str_replace(["\r\n","\r"], "\n", $_POST['project_body_completed'] ?? '')),
                    'subject_updated'   => sanitize_text_field($_POST['project_subject_updated'] ?? ''),
                    'body_updated'      => wp_kses_post(str_replace(["\r\n","\r"], "\n", $_POST['project_body_updated'] ?? '')),
                ];
                $o['project_templates'] = $pt;
                update_option(self::OPT_KEY, $o);
                echo '<div class="updated notice"><p>Template do projeto guardado.</p></div>';
            }

            if (isset($_POST['delete_project_tpl']) && $sel_project) {
                if (isset($o['project_templates'][$sel_project])) {
                    unset($o['project_templates'][$sel_project]);
                    update_option(self::OPT_KEY, $o);
                    echo '<div class="updated notice"><p>Override do projeto removido. A usar template global.</p></div>';
                }
            }

            if (isset($_POST['send_test'])) {
                $ctx = self::build_demo_context($sel_client, $sel_project);
                $tpl = self::get_project_template($sel_project, $o);
                $subject = self::apply_placeholders($preview_type === 'completed' ? $tpl['subject_completed'] : $tpl['subject_updated'], $ctx);
                $body    = self::apply_placeholders($preview_type === 'completed' ? $tpl['body_completed'] : $tpl['body_updated'], $ctx);
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                if (!empty($o['from_name']) || !empty($o['from_email'])) {
                    $fromEmail = sanitize_email($o['from_email'] ?: get_bloginfo('admin_email'));
                    $fromName  = wp_specialchars_decode($o['from_name'] ?: get_bloginfo('name'), ENT_QUOTES);
                    if ($fromEmail) $headers[] = 'From: '.$fromName.' <'.$fromEmail.'>';
                }
                if (!empty($o['reply_to'])) {
                    $headers[] = 'Reply-To: '.sanitize_email($o['reply_to']);
                }
                $user = wp_get_current_user();
                if ($user && !empty($user->user_email)) {
                    $html = !empty($o['send_as_html']) ? self::render_message_html($subject, $body, [
                        'brand_primary' => $o['brand_primary'] ?? '',
                        'footer_text'   => $o['footer_text'] ?? '',
                        'button_label'  => $o['button_label'] ?? 'Abrir rota',
                        'route_url'     => $ctx['{route_url}'] ?? '',
                    ]) : nl2br(esc_html(wp_strip_all_tags($body)));
                    wp_mail($user->user_email, $subject, $html, $headers);
                    echo '<div class="updated notice"><p>Email de teste enviado para '.esc_html($user->user_email).'.</p></div>';
                } else {
                    echo '<div class="error notice"><p>Sem email de utilizador para envio de teste.</p></div>';
                }
            }

            $o = self::get();
        }

        $project_override = ($sel_project && !empty($o['project_templates'][$sel_project])) ? $o['project_templates'][$sel_project] : [
            'subject_completed' => '',
            'body_completed'    => '',
            'subject_updated'   => '',
            'body_updated'      => '',
        ];
        $routes30   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$px}routes WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $activeProj = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$px}projects WHERE status='active'");
        $overrides  = self::count_overrides($o);
        $recipients = count(array_filter(array_map('trim', explode(',', (string)($o['extra_emails'] ?? ''))))) + (!empty($o['to_client']) ? 1 : 0) + (!empty($o['to_collaborator']) ? 1 : 0);

        $ctx = self::build_demo_context($sel_client, $sel_project);
        $tpl = self::get_project_template($sel_project, $o);
        $previewSubject = self::apply_placeholders($preview_type === 'completed' ? $tpl['subject_completed'] : $tpl['subject_updated'], $ctx);
        $previewBody    = self::apply_placeholders($preview_type === 'completed' ? $tpl['body_completed'] : $tpl['body_updated'], $ctx);
        $previewHtml    = self::render_message_html($previewSubject, $previewBody, [
            'brand_primary' => $o['brand_primary'] ?? '',
            'footer_text'   => $o['footer_text'] ?? '',
            'button_label'  => $o['button_label'] ?? 'Abrir rota',
            'route_url'     => $ctx['{route_url}'] ?? '',
        ]);
        ?>
        <div class="wrap routespro-emails-wrap">
          <h1 style="margin-bottom:16px">E-mails</h1>
          <style>
            .routespro-emails-wrap .rp-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;align-items:start}
            .routespro-emails-wrap .rp-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
            .routespro-emails-wrap .rp-hero{background:linear-gradient(135deg,#111827 0%,#374151 100%);color:#fff;padding:22px;border-radius:22px;margin:8px 0 18px}
            .routespro-emails-wrap .rp-hero h2{margin:0 0 8px;font-size:24px}
            .routespro-emails-wrap .rp-hero p{margin:0;color:rgba(255,255,255,.85);max-width:900px}
            .routespro-emails-wrap .rp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0 18px}
            .routespro-emails-wrap .rp-stat{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:14px 16px}
            .routespro-emails-wrap .rp-stat b{display:block;font-size:24px;line-height:1.1;margin-bottom:6px}
            .routespro-emails-wrap .rp-muted{color:#6b7280}
            .routespro-emails-wrap .rp-field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
            .routespro-emails-wrap input[type=text], .routespro-emails-wrap input[type=email], .routespro-emails-wrap select, .routespro-emails-wrap textarea{width:100%;max-width:none;border:1px solid #d1d5db;border-radius:12px;padding:10px 12px}
            .routespro-emails-wrap textarea{min-height:150px}
            .routespro-emails-wrap .rp-inline{display:flex;gap:16px;flex-wrap:wrap;align-items:center}
            .routespro-emails-wrap .rp-label{font-weight:600;margin:0 0 8px;display:block}
            .routespro-emails-wrap .rp-token-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px}
            .routespro-emails-wrap code{background:#f3f4f6;border-radius:8px;padding:2px 6px}
            .routespro-emails-wrap .rp-preview-shell{border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;background:#f3f4f6}
            .routespro-emails-wrap .rp-preview-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;background:#fff;border-bottom:1px solid #e5e7eb}
            .routespro-emails-wrap .rp-preview-body{padding:12px}
            .routespro-emails-wrap .rp-preview-body iframe{width:100%;height:720px;border:0;background:#fff;border-radius:14px}
            .routespro-emails-wrap .rp-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
            @media (max-width: 1280px){.routespro-emails-wrap .rp-grid{grid-template-columns:1fr}.routespro-emails-wrap .rp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
            @media (max-width: 782px){.routespro-emails-wrap .rp-field-grid,.routespro-emails-wrap .rp-token-list,.routespro-emails-wrap .rp-stats{grid-template-columns:1fr}}
          </style>

          <div class="rp-hero">
            <h2>Centro de e-mails operacional</h2>
            <p>Fecha aqui a integração do BO com clientes, projetos, rotas e responsáveis. O foco desta área é simples, template global sólido, override por projeto, preview vivo e envio de teste com branding alinhado ao resto do plugin.</p>
          </div>

          <div class="rp-stats">
            <div class="rp-stat"><b><?php echo (int) $routes30; ?></b><span class="rp-muted">Rotas nos últimos 30 dias</span></div>
            <div class="rp-stat"><b><?php echo (int) $activeProj; ?></b><span class="rp-muted">Projetos ativos</span></div>
            <div class="rp-stat"><b><?php echo (int) $overrides; ?></b><span class="rp-muted">Overrides por projeto</span></div>
            <div class="rp-stat"><b><?php echo (int) $recipients; ?></b><span class="rp-muted">Canais de destino ativos</span></div>
          </div>

          <form method="post">
            <?php wp_nonce_field('routespro_emails','routespro_emails_nonce'); ?>
            <div class="rp-grid">
              <div>
                <div class="rp-card" style="margin-bottom:18px">
                  <h2 style="margin-top:0">Automação e destinatários</h2>
                  <div class="rp-inline" style="margin-bottom:14px">
                    <label><input type="checkbox" name="on_completed" <?php checked($o['on_completed'],1); ?>> Enviar quando a rota fica concluída</label>
                    <label><input type="checkbox" name="on_updated" <?php checked($o['on_updated'],1); ?>> Enviar quando a rota é atualizada</label>
                  </div>
                  <div class="rp-inline" style="margin-bottom:14px">
                    <label><input type="checkbox" name="to_client" <?php checked($o['to_client'],1); ?>> Cliente</label>
                    <label><input type="checkbox" name="to_collaborator" <?php checked($o['to_collaborator'],1); ?>> Colaborador responsável</label>
                    <label><input type="checkbox" name="send_as_html" <?php checked($o['send_as_html'],1); ?>> Enviar em HTML premium</label>
                  </div>
                  <label class="rp-label">Emails extra, separados por vírgula</label>
                  <input type="text" name="extra_emails" value="<?php echo esc_attr($o['extra_emails']); ?>" placeholder="gerente@empresa.pt, supervisao@empresa.pt">
                </div>

                <div class="rp-card" style="margin-bottom:18px">
                  <h2 style="margin-top:0">Branding e cabeçalhos</h2>
                  <div class="rp-field-grid">
                    <div>
                      <label class="rp-label">Nome do remetente</label>
                      <input type="text" name="from_name" value="<?php echo esc_attr($o['from_name']); ?>" placeholder="RoutesPro">
                    </div>
                    <div>
                      <label class="rp-label">Email do remetente</label>
                      <input type="email" name="from_email" value="<?php echo esc_attr($o['from_email']); ?>" placeholder="no-reply@empresa.pt">
                    </div>
                    <div>
                      <label class="rp-label">Reply-To</label>
                      <input type="email" name="reply_to" value="<?php echo esc_attr($o['reply_to']); ?>" placeholder="operacoes@empresa.pt">
                    </div>
                    <div>
                      <label class="rp-label">Cor principal</label>
                      <input type="text" name="brand_primary" value="<?php echo esc_attr($o['brand_primary']); ?>" placeholder="#111827">
                    </div>
                    <div>
                      <label class="rp-label">Texto do botão</label>
                      <input type="text" name="button_label" value="<?php echo esc_attr($o['button_label']); ?>" placeholder="Abrir rota">
                    </div>
                    <div>
                      <label class="rp-label">Texto de rodapé</label>
                      <input type="text" name="footer_text" value="<?php echo esc_attr($o['footer_text']); ?>" placeholder="Notificação automática gerada pelo RoutesPro.">
                    </div>
                  </div>
                </div>

                <div class="rp-card" style="margin-bottom:18px">
                  <h2 style="margin-top:0">Templates globais</h2>
                  <p class="rp-muted" style="margin-top:0">Estes textos servem como base para toda a operação. O projeto só substitui o que precisares mesmo de personalizar.</p>
                  <div class="rp-field-grid">
                    <div>
                      <label class="rp-label">Assunto, rota concluída</label>
                      <input type="text" name="subject_completed" value="<?php echo esc_attr($o['subject_completed']); ?>">
                    </div>
                    <div>
                      <label class="rp-label">Assunto, rota atualizada</label>
                      <input type="text" name="subject_updated" value="<?php echo esc_attr($o['subject_updated']); ?>">
                    </div>
                    <div style="grid-column:1/-1">
                      <label class="rp-label">Corpo, rota concluída</label>
                      <textarea name="body_completed" class="code"><?php echo esc_textarea($o['body_completed']); ?></textarea>
                    </div>
                    <div style="grid-column:1/-1">
                      <label class="rp-label">Corpo, rota atualizada</label>
                      <textarea name="body_updated" class="code"><?php echo esc_textarea($o['body_updated']); ?></textarea>
                    </div>
                  </div>
                  <div class="rp-actions">
                    <button class="button button-primary" name="save_global" value="1">Guardar configuração global</button>
                  </div>
                </div>

                <div class="rp-card">
                  <h2 style="margin-top:0">Override por projeto</h2>
                  <p class="rp-muted" style="margin-top:0">Útil para campanhas com comunicação própria, sem partir a coerência global do BO.</p>
                  <div class="rp-field-grid" style="margin-bottom:14px">
                    <div>
                      <label class="rp-label">Cliente</label>
                      <select name="preview_client_id" onchange="this.form.submit()">
                        <option value="">Selecionar</option>
                        <?php foreach($clients as $c): ?>
                          <option value="<?php echo intval($c['id']); ?>" <?php selected($sel_client, $c['id']); ?>><?php echo esc_html($c['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="rp-label">Projeto ou campanha</label>
                      <select name="preview_project_id" onchange="this.form.submit()">
                        <option value="">Selecionar</option>
                        <?php foreach($projects as $p): ?>
                          <option value="<?php echo intval($p['id']); ?>" <?php selected($sel_project, $p['id']); ?>><?php echo esc_html($p['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="rp-label">Assunto, rota concluída</label>
                      <input type="text" name="project_subject_completed" value="<?php echo esc_attr($project_override['subject_completed']); ?>">
                    </div>
                    <div>
                      <label class="rp-label">Assunto, rota atualizada</label>
                      <input type="text" name="project_subject_updated" value="<?php echo esc_attr($project_override['subject_updated']); ?>">
                    </div>
                    <div style="grid-column:1/-1">
                      <label class="rp-label">Corpo, rota concluída</label>
                      <textarea name="project_body_completed" class="code"><?php echo esc_textarea($project_override['body_completed']); ?></textarea>
                    </div>
                    <div style="grid-column:1/-1">
                      <label class="rp-label">Corpo, rota atualizada</label>
                      <textarea name="project_body_updated" class="code"><?php echo esc_textarea($project_override['body_updated']); ?></textarea>
                    </div>
                  </div>
                  <div class="rp-actions">
                    <button class="button button-primary" name="save_project_tpl" value="1" <?php disabled(!$sel_project); ?>>Guardar override do projeto</button>
                    <button class="button button-link-delete" name="delete_project_tpl" value="1" <?php disabled(!$sel_project || empty($o['project_templates'][$sel_project])); ?> onclick="return confirm('Remover override deste projeto?')">Apagar override</button>
                  </div>
                </div>
              </div>

              <div>
                <div class="rp-card" style="margin-bottom:18px">
                  <h2 style="margin-top:0">Placeholders e integração</h2>
                  <div class="rp-token-list">
                    <?php foreach (self::template_tokens() as $token => $label): ?>
                      <div><code><?php echo esc_html($token); ?></code><div class="rp-muted"><?php echo esc_html($label); ?></div></div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="rp-card" style="margin-bottom:18px">
                  <div class="rp-preview-head" style="padding:0 0 14px;border:0;background:none">
                    <div>
                      <h2 style="margin:0">Preview vivo</h2>
                      <div class="rp-muted">Puxa o template global ou o override do projeto selecionado.</div>
                    </div>
                  </div>
                  <div class="rp-field-grid" style="margin-bottom:14px">
                    <div>
                      <label class="rp-label">Tipo de pré visualização</label>
                      <select name="preview_type" onchange="this.form.submit()">
                        <option value="updated" <?php selected($preview_type, 'updated'); ?>>Rota atualizada</option>
                        <option value="completed" <?php selected($preview_type, 'completed'); ?>>Rota concluída</option>
                      </select>
                    </div>
                    <div>
                      <label class="rp-label">Assunto final</label>
                      <input type="text" value="<?php echo esc_attr($previewSubject); ?>" readonly>
                    </div>
                  </div>
                  <div class="rp-preview-shell">
                    <div class="rp-preview-head">
                      <strong><?php echo esc_html($previewSubject); ?></strong>
                      <span class="rp-muted">Branding BO</span>
                    </div>
                    <div class="rp-preview-body">
                      <iframe sandbox="allow-same-origin" srcdoc="<?php echo esc_attr($previewHtml); ?>"></iframe>
                    </div>
                  </div>
                  <div class="rp-actions">
                    <button class="button" name="send_test" value="1">Enviar teste para o meu email</button>
                  </div>
                </div>

                <div class="rp-card">
                  <h2 style="margin-top:0">Como isto fica ligado ao resto do plugin</h2>
                  <ul style="margin:0;padding-left:18px;line-height:1.8">
                    <li>Clientes, projetos e responsáveis alimentam automaticamente os placeholders.</li>
                    <li>Os overrides por projeto deixam alinhar campanhas PDV com comunicação própria.</li>
                    <li>O link da rota aponta para o BO, útil para operação, coordenação e validação rápida.</li>
                    <li>O corpo pode continuar simples em texto ou passar para HTML premium sem perder compatibilidade.</li>
                  </ul>
                </div>
              </div>
            </div>
          </form>
        </div>
        <?php
    }

    
public static function get_team_recipients(int $client_id = 0, int $project_id = 0): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $ids = [];

        if ($project_id > 0) {
            $project = $wpdb->get_row($wpdb->prepare("SELECT meta_json FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A) ?: [];
            $projectMeta = json_decode((string) ($project['meta_json'] ?? ''), true);
            if (is_array($projectMeta)) {
                $projectUsers = $projectMeta['associated_user_ids'] ?? ($projectMeta['user_ids'] ?? ($projectMeta['assigned_users'] ?? []));
                if (is_string($projectUsers)) $projectUsers = preg_split('/\s*,\s*/', trim($projectUsers));
                if (is_array($projectUsers)) {
                    foreach ($projectUsers as $value) {
                        if (is_array($value)) $value = $value['user_id'] ?? $value['id'] ?? 0;
                        $uid = absint($value);
                        if ($uid) $ids[$uid] = $uid;
                    }
                }
            }

            $projectAssigned = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$px}project_assignments WHERE project_id=%d AND is_active=1 ORDER BY user_id ASC",
                $project_id
            )) ?: [];
            foreach ($projectAssigned as $uid) {
                $uid = absint($uid);
                if ($uid) $ids[$uid] = $uid;
            }

            $assigned = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT assigned_to FROM {$px}campaign_locations WHERE project_id=%d AND assigned_to IS NOT NULL AND assigned_to > 0 ORDER BY assigned_to ASC",
                $project_id
            )) ?: [];
            foreach ($assigned as $uid) {
                $uid = absint($uid);
                if ($uid) $ids[$uid] = $uid;
            }

            $owners = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT owner_user_id FROM {$px}routes WHERE project_id=%d AND owner_user_id IS NOT NULL AND owner_user_id > 0 ORDER BY owner_user_id ASC",
                $project_id
            )) ?: [];
            foreach ($owners as $uid) {
                $uid = absint($uid);
                if ($uid) $ids[$uid] = $uid;
            }

            $routeAssignments = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT a.user_id FROM {$px}assignments a INNER JOIN {$px}routes r ON r.id = a.route_id WHERE r.project_id=%d AND a.is_active=1 ORDER BY a.user_id ASC",
                $project_id
            )) ?: [];
            foreach ($routeAssignments as $uid) {
                $uid = absint($uid);
                if ($uid) $ids[$uid] = $uid;
            }
        }

        if (!$ids) return [];

        $users = get_users([
            'include' => array_values($ids),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => ['ID','display_name','user_email','user_login'],
        ]);
        if (!is_array($users)) return [];

        $out = [];
        foreach ($users as $u) {
            $email = (string) ($u->user_email ?? '');
            if ($email === '') continue;
            $display = $u->display_name ?: $u->user_login ?: ('#' . $u->ID);
            $out[] = [
                'ID' => (int) $u->ID,
                'display_name' => (string) $display,
                'user_email' => $email,
                'user_login' => (string) ($u->user_login ?? ''),
                'label' => trim($display . ' • ' . $email),
            ];
        }
        return array_values($out);
    }

    public static function get_available_senders_for_user(int $client_id = 0, int $project_id = 0, ?int $logged_user_id = null): array {
        $logged_user_id = $logged_user_id ?: get_current_user_id();
        $associatedIds = \RoutesPro\Support\Permissions::get_associated_user_ids($client_id, $project_id);
        if (empty($associatedIds)) return [];

        $users = get_users([
            'include' => array_values(array_map('absint', $associatedIds)),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => ['ID','display_name','user_email','user_login'],
        ]);
        if (!is_array($users)) return [];

        $out = [];
        foreach ($users as $u) {
            $email = (string) ($u->user_email ?? '');
            if ($email === '') continue;
            $display = $u->display_name ?: $u->user_login ?: ('#' . $u->ID);
            $out[(int) $u->ID] = [
                'ID' => (int) $u->ID,
                'display_name' => (string) $display,
                'user_email' => $email,
                'user_login' => (string) ($u->user_login ?? ''),
                'label' => trim($display . ' • ' . $email),
            ];
        }
        if (!$out) return [];

        if ($logged_user_id && isset($out[(int) $logged_user_id])) {
            return [$out[(int) $logged_user_id]];
        }

        return array_values($out);
    }

    public static function get_message_app_url(int $log_id = 0): string {
        $target = home_url('/');
        $args = [];
        if ($log_id > 0) $args['routespro_message'] = $log_id;
        $url = add_query_arg($args, $target);
        return $url . '#rp-app-messages';
    }

    public static function log_email(array $args = []): int {
        global $wpdb;
        $table = $wpdb->prefix . 'routespro_email_logs';
        $data = [
            'email_type' => sanitize_key($args['email_type'] ?? 'system'),
            'context_key' => sanitize_key($args['context_key'] ?? ''),
            'client_id' => absint($args['client_id'] ?? 0) ?: null,
            'project_id' => absint($args['project_id'] ?? 0) ?: null,
            'route_id' => absint($args['route_id'] ?? 0) ?: null,
            'sender_user_id' => absint($args['sender_user_id'] ?? 0) ?: null,
            'recipient_user_id' => absint($args['recipient_user_id'] ?? 0) ?: null,
            'recipient_email' => sanitize_email($args['recipient_email'] ?? ''),
            'recipient_name' => sanitize_text_field($args['recipient_name'] ?? ''),
            'message_kind' => sanitize_text_field($args['message_kind'] ?? ''),
            'subject' => sanitize_text_field($args['subject'] ?? ''),
            'body' => wp_kses_post($args['body'] ?? ''),
            'meta_json' => wp_json_encode(is_array($args['meta'] ?? null) ? ($args['meta'] ?? []) : []),
            'mail_result' => sanitize_key($args['mail_result'] ?? 'sent'),
        ];
        $formats = ['%s','%s','%d','%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s'];
        $wpdb->insert($table, $data, $formats);
        return (int) $wpdb->insert_id;
    }


    private static function get_log_recipients(int $client_id = 0, int $project_id = 0): array {
        global $wpdb;
        $table = $wpdb->prefix . 'routespro_email_logs';
        $where = ['1=1'];
        $args = [];
        if ($client_id) { $where[] = 'client_id=%d'; $args[] = $client_id; }
        if ($project_id) { $where[] = 'project_id=%d'; $args[] = $project_id; }
        $sql = "SELECT recipient_user_id, recipient_email, recipient_name, MAX(created_at) AS last_seen
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                GROUP BY recipient_user_id, recipient_email, recipient_name
                ORDER BY last_seen DESC";
        $rows = $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
        $out = [];
        foreach ($rows as $row) {
            $email = sanitize_email((string) ($row['recipient_email'] ?? ''));
            $uid = absint($row['recipient_user_id'] ?? 0);
            $name = trim((string) ($row['recipient_name'] ?? ''));
            if (!$uid && $email === '') continue;
            $key = $uid ? ('u:' . $uid) : ('e:' . strtolower($email));
            $label = $name ?: $email ?: ('#' . $uid);
            if ($email && stripos($label, $email) === false) $label .= ' · ' . $email;
            $out[$key] = ['key' => $key, 'label' => $label];
        }
        return array_values($out);
    }



    
public static function get_user_message_logs(int $user_id, array $filters = []): array {
    global $wpdb;
    $table = $wpdb->prefix . 'routespro_email_logs';
    $px = $wpdb->prefix . 'routespro_';

    $user_id = absint($user_id);
    if (!$user_id) return [];

    $scope = \RoutesPro\Support\Permissions::get_scope($user_id);
    $user = get_userdata($user_id);
    $user_email = strtolower((string) ($user->user_email ?? ''));

    $project_ids = array_values(array_filter(array_map('absint', (array) ($scope['project_ids'] ?? []))));
    $client_ids  = array_values(array_filter(array_map('absint', (array) ($scope['client_ids'] ?? []))));
    $route_ids   = array_values(array_filter(array_map('absint', (array) ($scope['route_ids'] ?? []))));

    $metaProjects = $wpdb->get_results("SELECT id, client_id, meta_json FROM {$px}projects", ARRAY_A) ?: [];
    foreach ($metaProjects as $projectRow) {
        $meta = json_decode((string)($projectRow['meta_json'] ?? ''), true);
        if (!is_array($meta)) $meta = [];
        $values = $meta['associated_user_ids'] ?? ($meta['user_ids'] ?? ($meta['assigned_users'] ?? []));
        if (is_string($values)) $values = preg_split('/\s*,\s*/', trim($values));
        if (!is_array($values)) $values = [];
        foreach ($values as $value) {
            if (is_array($value)) $value = $value['user_id'] ?? $value['id'] ?? 0;
            $value = absint($value);
            if ($value === $user_id) {
                $pid = (int)($projectRow['id'] ?? 0);
                $cid = (int)($projectRow['client_id'] ?? 0);
                if ($pid) $project_ids[] = $pid;
                if ($cid) $client_ids[] = $cid;
            }
        }
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pa.project_id, p.client_id
         FROM {$px}project_assignments pa
         INNER JOIN {$px}projects p ON p.id = pa.project_id
         WHERE pa.user_id = %d AND pa.is_active = 1",
        $user_id
    ), ARRAY_A) ?: [];
    foreach ($rows as $row) {
        $pid = (int)($row['project_id'] ?? 0);
        $cid = (int)($row['client_id'] ?? 0);
        if ($pid) $project_ids[] = $pid;
        if ($cid) $client_ids[] = $cid;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT r.id AS route_id, r.project_id, r.client_id
         FROM {$px}routes r
         LEFT JOIN {$px}assignments a ON a.route_id = r.id AND a.is_active = 1
         WHERE r.owner_user_id = %d OR a.user_id = %d",
        $user_id,
        $user_id
    ), ARRAY_A) ?: [];
    foreach ($rows as $row) {
        $rid = (int)($row['route_id'] ?? 0);
        $pid = (int)($row['project_id'] ?? 0);
        $cid = (int)($row['client_id'] ?? 0);
        if ($rid) $route_ids[] = $rid;
        if ($pid) $project_ids[] = $pid;
        if ($cid) $client_ids[] = $cid;
    }

    $project_ids = array_values(array_unique(array_filter(array_map('absint', $project_ids))));
    $client_ids  = array_values(array_unique(array_filter(array_map('absint', $client_ids))));
    $route_ids   = array_values(array_unique(array_filter(array_map('absint', $route_ids))));

    $where = ["el.email_type IN ('client_team_message','client_team_message_reply')"];
    $args = [];

    if (!empty($filters['project_id'])) {
        $where[] = 'el.project_id = %d';
        $args[] = absint($filters['project_id']);
    }
    if (!empty($filters['client_id'])) {
        $where[] = 'el.client_id = %d';
        $args[] = absint($filters['client_id']);
    }
    if (!empty($filters['message_id'])) {
        $where[] = 'el.id = %d';
        $args[] = absint($filters['message_id']);
    }

    $sql = "SELECT
                el.*,
                c.name AS client_name,
                p.name AS project_name,
                su.display_name AS sender_name,
                su.user_email AS sender_email_joined,
                ru.display_name AS recipient_user_name,
                ru.user_email AS recipient_user_email
            FROM {$table} el
            LEFT JOIN {$wpdb->prefix}routespro_clients c ON c.id = el.client_id
            LEFT JOIN {$wpdb->prefix}routespro_projects p ON p.id = el.project_id
            LEFT JOIN {$wpdb->users} su ON su.ID = el.sender_user_id
            LEFT JOIN {$wpdb->users} ru ON ru.ID = el.recipient_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY el.created_at DESC, el.id DESC
            LIMIT 800";

    $rows = $args
        ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [])
        : ($wpdb->get_results($sql, ARRAY_A) ?: []);

    $statusFilter = sanitize_text_field((string)($filters['status'] ?? ''));
    $filterProjectId = absint($filters['project_id'] ?? 0);
    $filterClientId = absint($filters['client_id'] ?? 0);
    $out = [];

    foreach ($rows as $row) {
        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        if (!is_array($meta)) $meta = [];

        $workflowStatus = sanitize_text_field((string)($meta['workflow_status'] ?? 'novo'));
        if ($workflowStatus === '') $workflowStatus = 'novo';

        $rowProjectId = (int)($row['project_id'] ?? 0);
        $rowClientId  = (int)($row['client_id'] ?? 0);
        $rowRouteId   = (int)($row['route_id'] ?? 0);

        $submittedByUserId    = absint($meta['submitted_by_user_id'] ?? 0);
        $selectedSenderUserId = absint($meta['selected_sender_user_id'] ?? 0);

        $submittedByEmail     = strtolower((string)($meta['submitted_by_email'] ?? ''));
        $selectedSenderEmail  = strtolower((string)($meta['selected_sender_email'] ?? ''));
        $recipientEmail       = strtolower((string)($row['recipient_email'] ?? ''));
        $senderEmailJoined    = strtolower((string)($row['sender_email_joined'] ?? ''));
        $recipientUserEmail   = strtolower((string)($row['recipient_user_email'] ?? ''));

        $isDirectMessage =
            ((int)($row['recipient_user_id'] ?? 0) === $user_id)
            || ((int)($row['sender_user_id'] ?? 0) === $user_id)
            || ($submittedByUserId === $user_id)
            || ($selectedSenderUserId === $user_id)
            || ($user_email !== '' && (
                $recipientEmail === $user_email
                || $senderEmailJoined === $user_email
                || $recipientUserEmail === $user_email
                || $submittedByEmail === $user_email
                || $selectedSenderEmail === $user_email
            ));

        $isScopeVisible =
            ($rowProjectId && in_array($rowProjectId, $project_ids, true))
            || ($rowClientId && in_array($rowClientId, $client_ids, true))
            || ($rowRouteId && in_array($rowRouteId, $route_ids, true));

        $matchesExplicitFilter =
            ($filterProjectId > 0 && $rowProjectId === $filterProjectId)
            || ($filterClientId > 0 && !$filterProjectId && $rowClientId === $filterClientId);

        if (!$isDirectMessage && !$isScopeVisible && !$matchesExplicitFilter) {
            continue;
        }

        if ($statusFilter !== '' && $workflowStatus !== $statusFilter) {
            continue;
        }

        if (empty($row['sender_name']) && !empty($meta['selected_sender_user_id'])) {
            $senderUser = get_userdata((int)$meta['selected_sender_user_id']);
            if ($senderUser) $row['sender_name'] = (string)($senderUser->display_name ?: $senderUser->user_login);
        }
        if (empty($row['sender_name']) && !empty($meta['selected_sender_email'])) {
            $row['sender_name'] = (string)$meta['selected_sender_email'];
        }

        $row['meta'] = $meta;
        $row['workflow_status'] = $workflowStatus;
        $row['app_url'] = self::get_message_app_url((int)($row['id'] ?? 0));
        $out[] = $row;
    }

    return array_values($out);
}


    private static function handle_log_actions(string $table): void {
        global $wpdb;
        if (!current_user_can('routespro_manage')) return;

        if (isset($_GET['rp_action']) && $_GET['rp_action'] === 'export') {
            check_admin_referer('routespro_email_log_export');
            self::export_log_csv($table);
        }

        if (!empty($_POST['routespro_email_log_action']) && check_admin_referer('routespro_email_log_save', 'routespro_email_log_nonce')) {
            $action = sanitize_key($_POST['routespro_email_log_action']);
            $log_id = absint($_POST['log_id'] ?? 0);
            if ($log_id) {
                if ($action === 'save') {
                    $wpdb->update($table, [
                        'recipient_name' => sanitize_text_field($_POST['recipient_name'] ?? ''),
                        'recipient_email' => sanitize_email($_POST['recipient_email'] ?? ''),
                        'message_kind' => sanitize_text_field($_POST['message_kind'] ?? ''),
                        'subject' => sanitize_text_field($_POST['subject'] ?? ''),
                        'mail_result' => sanitize_key($_POST['mail_result'] ?? 'sent'),
                    ], ['id' => $log_id], ['%s','%s','%s','%s','%s'], ['%d']);
                    add_settings_error('routespro_email_log', 'saved', 'Email atualizado com sucesso.', 'updated');
                } elseif ($action === 'delete') {
                    $wpdb->delete($table, ['id' => $log_id], ['%d']);
                    add_settings_error('routespro_email_log', 'deleted', 'Email apagado com sucesso.', 'updated');
                }
            }
        } elseif (isset($_GET['rp_action'], $_GET['log_id']) && $_GET['rp_action'] === 'delete') {
            check_admin_referer('routespro_email_log_delete_' . absint($_GET['log_id']));
            $wpdb->delete($table, ['id' => absint($_GET['log_id'])], ['%d']);
            add_settings_error('routespro_email_log', 'deleted', 'Email apagado com sucesso.', 'updated');
        }
    }

    private static function export_log_csv(string $table): void {
        global $wpdb;
        [$where_sql, $args] = self::build_log_filters($wpdb);
        $sql = "SELECT el.*, c.name AS client_name, p.name AS project_name, su.display_name AS sender_name, ru.display_name AS recipient_user_name
                FROM {$table} el
                LEFT JOIN {$wpdb->prefix}routespro_clients c ON c.id = el.client_id
                LEFT JOIN {$wpdb->prefix}routespro_projects p ON p.id = el.project_id
                LEFT JOIN {$wpdb->users} su ON su.ID = el.sender_user_id
                LEFT JOIN {$wpdb->users} ru ON ru.ID = el.recipient_user_id
                WHERE {$where_sql}
                ORDER BY el.created_at DESC, el.id DESC";
        $rows = $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=routespro-email-log.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','data','tipo','cliente','campanha','destinatario','destinatario_email','categoria','assunto','remetente','estado']);
        foreach ($rows as $row) {
            fputcsv($out, [
                (int) $row['id'],
                (string) $row['created_at'],
                (string) $row['email_type'],
                (string) ($row['client_name'] ?: ''),
                (string) ($row['project_name'] ?: ''),
                (string) ($row['recipient_user_name'] ?: $row['recipient_name'] ?: $row['recipient_email']),
                (string) ($row['recipient_email'] ?: ''),
                (string) ($row['message_kind'] ?: ''),
                (string) ($row['subject'] ?: ''),
                (string) ($row['sender_name'] ?: ''),
                (string) ($row['mail_result'] ?: ''),
            ]);
        }
        fclose($out);
        exit;
    }

    private static function build_log_filters($wpdb): array {
        $client_id = absint($_GET['client_id'] ?? 0);
        $project_id = absint($_GET['project_id'] ?? 0);
        $recipient_key = sanitize_text_field($_GET['recipient_key'] ?? '');
        if ($recipient_key === '' && !empty($_GET['recipient_user_id'])) $recipient_key = 'u:' . absint($_GET['recipient_user_id']);
        $email_type = sanitize_key($_GET['email_type'] ?? '');
        $message_kind = sanitize_text_field($_GET['message_kind'] ?? '');
        $q = sanitize_text_field($_GET['q'] ?? '');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to = sanitize_text_field($_GET['date_to'] ?? '');

        $where = ['1=1'];
        $args = [];
        if ($client_id) { $where[] = 'el.client_id=%d'; $args[] = $client_id; }
        if ($project_id) { $where[] = 'el.project_id=%d'; $args[] = $project_id; }
        if ($recipient_key !== '') {
            if (strpos($recipient_key, 'u:') === 0) {
                $where[] = 'el.recipient_user_id=%d'; $args[] = absint(substr($recipient_key, 2));
            } elseif (strpos($recipient_key, 'e:') === 0) {
                $where[] = 'LOWER(el.recipient_email)=%s'; $args[] = strtolower(substr($recipient_key, 2));
            }
        }
        if ($email_type !== '') { $where[] = 'el.email_type=%s'; $args[] = $email_type; }
        if ($message_kind !== '') { $where[] = 'el.message_kind=%s'; $args[] = $message_kind; }
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(el.subject LIKE %s OR el.recipient_email LIKE %s OR el.recipient_name LIKE %s OR ru.display_name LIKE %s OR el.body LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        if ($date_from !== '') { $where[] = 'DATE(el.created_at) >= %s'; $args[] = $date_from; }
        if ($date_to !== '') { $where[] = 'DATE(el.created_at) <= %s'; $args[] = $date_to; }
        return [implode(' AND ', $where), $args];
    }

    public static function render_log() {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $table = $wpdb->prefix . 'routespro_email_logs';
        self::handle_log_actions($table);

        $client_id = absint($_GET['client_id'] ?? 0);
        $project_id = absint($_GET['project_id'] ?? 0);
        $recipient_key = sanitize_text_field($_GET['recipient_key'] ?? '');
        if ($recipient_key === '' && !empty($_GET['recipient_user_id'])) $recipient_key = 'u:' . absint($_GET['recipient_user_id']);
        $email_type = sanitize_key($_GET['email_type'] ?? '');
        $message_kind = sanitize_text_field($_GET['message_kind'] ?? '');
        $q = sanitize_text_field($_GET['q'] ?? '');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to = sanitize_text_field($_GET['date_to'] ?? '');
        $page_num = max(1, absint($_GET['paged'] ?? 1));
        $per_page = absint($_GET['per_page'] ?? 25);
        if (!in_array($per_page, [25,50,100,250], true)) $per_page = 25;
        $edit_id = absint($_GET['edit_id'] ?? 0);

        $clients = $wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
        $projects = $client_id ? ($wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$px}projects WHERE client_id=%d ORDER BY name ASC", $client_id), ARRAY_A) ?: []) : ($wpdb->get_results("SELECT id,name FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: []);
        $recipients = self::get_log_recipients($client_id, $project_id);
        [$where_sql, $args] = self::build_log_filters($wpdb);

        $count_sql = "SELECT COUNT(*) FROM {$table} el LEFT JOIN {$wpdb->users} ru ON ru.ID = el.recipient_user_id WHERE {$where_sql}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, ...$args)) : $wpdb->get_var($count_sql));
        $total_pages = max(1, (int) ceil($total / max(1, $per_page)));
        if ($page_num > $total_pages) $page_num = $total_pages;
        $offset = ($page_num - 1) * $per_page;

        $sql = "SELECT el.*, c.name AS client_name, p.name AS project_name, su.display_name AS sender_name, ru.display_name AS recipient_user_name
                FROM {$table} el
                LEFT JOIN {$px}clients c ON c.id = el.client_id
                LEFT JOIN {$px}projects p ON p.id = el.project_id
                LEFT JOIN {$wpdb->users} su ON su.ID = el.sender_user_id
                LEFT JOIN {$wpdb->users} ru ON ru.ID = el.recipient_user_id
                WHERE {$where_sql}
                ORDER BY el.created_at DESC, el.id DESC
                LIMIT %d OFFSET %d";
        $rows_args = array_values($args);
        $rows_args[] = $per_page;
        $rows_args[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$rows_args), ARRAY_A) ?: [];
        $edit_row = $edit_id ? ($wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $edit_id), ARRAY_A) ?: null) : null;

        $base_url = admin_url('admin.php?page=routespro-email-log');
        echo '<div class="wrap">';
        \RoutesPro\Admin\Branding::render_header('E-mails enviados', 'Tabela operacional para seguir tudo o que saiu do sistema, incluindo mensagens do portal para a equipa.');
        settings_errors('routespro_email_log');
        echo '<div class="routespro-card" style="margin-top:18px">';
        echo '<form method="get" style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;align-items:end">';
        echo '<input type="hidden" name="page" value="routespro-email-log">';
        echo '<label>Cliente<br><select name="client_id"><option value="0">Todos</option>';
        foreach($clients as $c) echo '<option value="'.intval($c['id']).'" '.selected($client_id,(int)$c['id'],false).'>'.esc_html($c['name']).'</option>';
        echo '</select></label>';
        echo '<label>Campanha<br><select name="project_id"><option value="0">Todas</option>';
        foreach($projects as $p) echo '<option value="'.intval($p['id']).'" '.selected($project_id,(int)$p['id'],false).'>'.esc_html($p['name']).'</option>';
        echo '</select></label>';
        echo '<label>Destinatário<br><select name="recipient_key"><option value="">Todos</option>';
        foreach($recipients as $u) echo '<option value="'.esc_attr($u['key']).'" '.selected($recipient_key,$u['key'],false).'>'.esc_html($u['label']).'</option>';
        echo '</select></label>';
        echo '<label>Tipo<br><select name="email_type"><option value="">Todos</option>';
        foreach(['route_notification'=>'Notificação rota','client_team_message'=>'Portal cliente'] as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected($email_type,$k,false).'>'.esc_html($label).'</option>';
        echo '</select></label>';
        echo '<label>Categoria<br><select name="message_kind"><option value="">Todas</option>';
        foreach(['corretiva'=>'Corretiva','preventiva'=>'Preventiva','informacao'=>'Informação','geral'=>'Geral'] as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected($message_kind,$k,false).'>'.esc_html($label).'</option>';
        echo '</select></label>';
        echo '<label>Pesquisa<br><input type="text" name="q" value="'.esc_attr($q).'" placeholder="Assunto, destinatário, texto"></label>';
        echo '<label>De<br><input type="date" name="date_from" value="'.esc_attr($date_from).'"></label>';
        echo '<label>Até<br><input type="date" name="date_to" value="'.esc_attr($date_to).'"></label>';
        echo '<label>Por página<br><select name="per_page">';
        foreach([25,50,100,250] as $n) echo '<option value="'.$n.'" '.selected($per_page,$n,false).'>'.$n.'</option>';
        echo '</select></label>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button button-primary">Filtrar</button>';
        $export_url = wp_nonce_url(add_query_arg([
            'page' => 'routespro-email-log',
            'client_id' => $client_id,
            'project_id' => $project_id,
            'recipient_key' => $recipient_key,
            'email_type' => $email_type,
            'message_kind' => $message_kind,
            'q' => $q,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'per_page' => $per_page,
            'rp_action' => 'export',
        ], $base_url), 'routespro_email_log_export');
        echo '<a class="button" href="'.esc_url($export_url).'">Exportar CSV</a></div>';
        echo '</form>';
        echo '<p style="margin:14px 0 10px;color:#64748b">A mostrar '.($total ? ($offset+1) : 0).' a '.min($total, $offset + $per_page).' de '.$total.' emails registados.</p>';

        if ($edit_row) {
            echo '<div style="margin:0 0 18px;padding:16px;border:1px solid #dbeafe;background:#f8fbff;border-radius:16px">';
            echo '<h3 style="margin-top:0">Editar email #'.intval($edit_row['id']).'</h3>';
            echo '<form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px">';
            wp_nonce_field('routespro_email_log_save', 'routespro_email_log_nonce');
            echo '<input type="hidden" name="routespro_email_log_action" value="save">';
            echo '<input type="hidden" name="log_id" value="'.intval($edit_row['id']).'">';
            echo '<label>Destinatário<br><input type="text" name="recipient_name" value="'.esc_attr((string)$edit_row['recipient_name']).'"></label>';
            echo '<label>Email<br><input type="email" name="recipient_email" value="'.esc_attr((string)$edit_row['recipient_email']).'"></label>';
            echo '<label>Categoria<br><select name="message_kind">';
            foreach([''=>'','corretiva'=>'Corretiva','preventiva'=>'Preventiva','informacao'=>'Informação','geral'=>'Geral'] as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected((string)$edit_row['message_kind'],$k,false).'>'.esc_html($label ?: 'Sem categoria').'</option>';
            echo '</select></label>';
            echo '<label>Estado<br><select name="mail_result">';
            foreach(['sent'=>'Enviado','failed'=>'Falhado','deleted'=>'Apagado','edited'=>'Editado'] as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected((string)$edit_row['mail_result'],$k,false).'>'.esc_html($label).'</option>';
            echo '</select></label>';
            echo '<label style="grid-column:1/-1">Assunto<br><input type="text" name="subject" value="'.esc_attr((string)$edit_row['subject']).'"></label>';
            echo '<div style="grid-column:1/-1;display:flex;gap:10px"><button class="button button-primary">Guardar alterações</button><a class="button" href="'.esc_url(remove_query_arg('edit_id')).'">Cancelar</a></div>';
            echo '</form></div>';
        }

        echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>Data</th><th>Tipo</th><th>Cliente</th><th>Campanha</th><th>Destinatário</th><th>Categoria</th><th>Assunto</th><th>Remetente</th><th>Estado</th><th>Ações</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="10">Sem emails para os filtros atuais.</td></tr>';
        } else {
            foreach($rows as $row){
                $recipient = $row['recipient_user_name'] ?: $row['recipient_name'] ?: $row['recipient_email'];
                $sender = $row['sender_name'] ?: ('#' . (int)($row['sender_user_id'] ?? 0));
                $edit_url = add_query_arg([
                    'page' => 'routespro-email-log', 'edit_id' => (int)$row['id'], 'client_id'=>$client_id, 'project_id'=>$project_id, 'recipient_key'=>$recipient_key, 'email_type'=>$email_type, 'message_kind'=>$message_kind, 'q'=>$q, 'date_from'=>$date_from, 'date_to'=>$date_to, 'per_page'=>$per_page, 'paged'=>$page_num,
                ], $base_url);
                $delete_url = wp_nonce_url(add_query_arg([
                    'page' => 'routespro-email-log', 'rp_action' => 'delete', 'log_id' => (int)$row['id'], 'client_id'=>$client_id, 'project_id'=>$project_id, 'recipient_key'=>$recipient_key, 'email_type'=>$email_type, 'message_kind'=>$message_kind, 'q'=>$q, 'date_from'=>$date_from, 'date_to'=>$date_to, 'per_page'=>$per_page, 'paged'=>$page_num,
                ], $base_url), 'routespro_email_log_delete_' . (int)$row['id']);
                echo '<tr>';
                echo '<td>'.esc_html(mysql2date('Y-m-d H:i', (string)$row['created_at'], false)).'</td>';
                echo '<td>'.esc_html((string)$row['email_type']).'</td>';
                echo '<td>'.esc_html((string)($row['client_name'] ?: '-')).'</td>';
                echo '<td>'.esc_html((string)($row['project_name'] ?: '-')).'</td>';
                echo '<td>'.esc_html((string)$recipient).'<br><span style="color:#64748b">'.esc_html((string)($row['recipient_email'] ?: '')).'</span></td>';
                echo '<td>'.esc_html((string)($row['message_kind'] ?: '-')).'</td>';
                echo '<td><strong>'.esc_html((string)$row['subject']).'</strong><div style="margin-top:6px;color:#64748b;max-width:480px">'.esc_html(wp_trim_words(wp_strip_all_tags((string)$row['body']), 20, '…')).'</div></td>';
                echo '<td>'.esc_html((string)$sender).'</td>';
                echo '<td>'.esc_html((string)($row['mail_result'] ?: 'sent')).'</td>';
                echo '<td><a href="'.esc_url($edit_url).'">Editar</a> · <a href="'.esc_url($delete_url).'" onclick="return confirm(\'Apagar este registo de email?\')">Apagar</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
        if ($total_pages > 1) {
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:16px">';
            for($i=max(1,$page_num-3); $i<=min($total_pages,$page_num+3); $i++){
                $url = add_query_arg([
                    'page' => 'routespro-email-log',
                    'client_id' => $client_id,
                    'project_id' => $project_id,
                    'recipient_key' => $recipient_key,
                    'email_type' => $email_type,
                    'message_kind' => $message_kind,
                    'q' => $q,
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                    'per_page' => $per_page,
                    'paged' => $i,
                ], $base_url);
                echo '<a class="button '.($i===$page_num?'button-primary':'').'" href="'.esc_url($url).'">'.$i.'</a>';
            }
            echo '</div>';
        }
        echo '</div></div>';
    }

}
