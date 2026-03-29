<?php
/**
 * AJAX endpoints para RoutesPro (BO/Front)
 * - Garantem autenticação e saneamento de inputs
 * - Alimentam selects de Projeto, Utilizador e Função
 * - Inclui uploader simples para “foto/prova” no front
 */

if (!defined('ABSPATH')) { exit; }

/**
 * CLIENTES acessíveis ao utilizador atual (owner ou assignment em alguma rota)
 */
add_action('wp_ajax_routespro_clients_for_user', function () {
    if (!is_user_logged_in()) wp_send_json([], 403);

    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $rows = $wpdb->get_results("SELECT id, name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
    $rows = \RoutesPro\Support\Permissions::filter_clients($rows);
    wp_send_json($rows ?: []);
});

/**
 * PROJETOS por CLIENTE (legacy – usado nalguns ecrãs)
 */
add_action('wp_ajax_routespro_projects_for_client', function () {
    if (!is_user_logged_in()) wp_send_json([], 403);

    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $cid = absint($_GET['client_id'] ?? 0);
    if ($cid && !\RoutesPro\Support\Permissions::is_allowed_client($cid)) wp_send_json([], 200);
    $sql = "SELECT id, name, client_id FROM {$px}projects" . ($cid ? $wpdb->prepare(" WHERE client_id=%d", $cid) : '') . " ORDER BY name ASC";
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
    $rows = \RoutesPro\Support\Permissions::filter_projects($rows);
    $rows = array_map(function($r){ return ['id'=>(int)$r['id'], 'name'=>(string)$r['name']]; }, $rows);
    wp_send_json($rows ?: []);
});

/**
 * NOVO: PROJETOS acessíveis ao utilizador (sem depender de cliente)
 * Filtros opcionais:
 *   - user_id: filtra por um funcionário específico (owner/assignment)
 *   - role:    filtra pela função na assignment
 */
add_action('wp_ajax_routespro_projects_for_user', function () {
    if (!is_user_logged_in()) wp_send_json([], 403);

    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $client_id = absint($_GET['client_id'] ?? 0);
    if ($client_id && !\RoutesPro\Support\Permissions::is_allowed_client($client_id)) wp_send_json([], 200);
    $sql = "SELECT id, name, client_id FROM {$px}projects" . ($client_id ? $wpdb->prepare(" WHERE client_id=%d", $client_id) : '') . " ORDER BY name ASC";
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
    $rows = \RoutesPro\Support\Permissions::filter_projects($rows);
    $rows = array_map(function($r){ return ['id'=>(int)$r['id'], 'name'=>(string)$r['name'], 'client_id'=>(int)$r['client_id']]; }, $rows);
    wp_send_json($rows ?: []);
});

/**
 * UTILIZADORES (para combos no BO / dashboard)
 * Retorna um label pronto para UI e campos úteis.
 */
add_action('wp_ajax_routespro_users', function () {
    if (!current_user_can('read')) wp_send_json([], 403);

    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $client_id = absint($_GET['client_id'] ?? 0);
    $project_id = absint($_GET['project_id'] ?? 0);
    $role = sanitize_key($_GET['role'] ?? '');
    $users = \RoutesPro\Support\Permissions::get_associated_users($client_id, $project_id, ['ID', 'display_name', 'user_email', 'user_login']);

    if ($client_id || $project_id || $role) {
        $where = ['1=1'];
        $args = [];
        if ($client_id > 0) { $where[] = 'r.client_id = %d'; $args[] = $client_id; }
        if ($project_id > 0) { $where[] = 'r.project_id = %d'; $args[] = $project_id; }
        if ($role !== '') { $where[] = 'a.role = %s'; $args[] = $role; }
        $sql = "SELECT DISTINCT u.ID, u.display_name, u.user_email, u.user_login
                FROM {$wpdb->users} u
                INNER JOIN {$px}assignments a ON a.user_id = u.ID
                INNER JOIN {$px}routes r ON r.id = a.route_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY COALESCE(NULLIF(u.display_name,''), u.user_login) ASC";
        $assignment_users = $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args)) ?: []) : ($wpdb->get_results($sql) ?: []);
        if (!empty($assignment_users)) {
            $merged = [];
            foreach ((array)$users as $u) { $merged[(int)$u->ID] = $u; }
            foreach ((array)$assignment_users as $u) { $merged[(int)$u->ID] = $u; }
            $users = array_values($merged);
        }
    }

    if (($client_id || $project_id) && is_user_logged_in()) {
        $current = wp_get_current_user();
        if ($current && !empty($current->ID)) {
            $found = false;
            foreach ((array)$users as $u) {
                if ((int)($u->ID ?? 0) === (int)$current->ID) { $found = true; break; }
            }
            if (!$found && \RoutesPro\Support\Permissions::can_access_front((int)$current->ID)) {
                $users[] = (object)[
                    'ID' => (int)$current->ID,
                    'display_name' => (string)$current->display_name,
                    'user_email' => (string)$current->user_email,
                    'user_login' => (string)$current->user_login,
                ];
            }
        }
    }

    if (!$users) {
        $users = [];
    }

    $out = array_map(function ($u) {
        $label = trim(($u->display_name ?: $u->user_login).' • '.($u->user_email ?: ''));
        return [
            'ID'          => (int)$u->ID,
            'displayName' => $u->display_name,
            'email'       => $u->user_email,
            'username'    => $u->user_login,
            'label'       => $label,
        ];
    }, $users);

    wp_send_json($out);
});

/**
 * FUNÇÕES (roles) para assignments
 * - Lista consolidada: defaults + DISTINCT na tabela assignments
 */
add_action('wp_ajax_routespro_roles', function () {
    if (!is_user_logged_in()) wp_send_json([], 403);

    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $client_id = absint($_GET['client_id'] ?? 0);
    $project_id = absint($_GET['project_id'] ?? 0);
    if ($client_id && !\RoutesPro\Support\Permissions::is_allowed_client($client_id)) wp_send_json([], 200);
    if ($project_id && !\RoutesPro\Support\Permissions::is_allowed_project($project_id)) wp_send_json([], 200);

    $defaults = ['implementacao','merchandising','comercial','driver'];
    $where = ["role IS NOT NULL", "role <> ''"];
    $args = [];
    if ($client_id > 0 || $project_id > 0) {
        $where[] = "route_id IN (SELECT id FROM {$px}routes WHERE 1=1" . ($client_id > 0 ? " AND client_id = %d" : '') . ($project_id > 0 ? " AND project_id = %d" : '') . ")";
        if ($client_id > 0) $args[] = $client_id;
        if ($project_id > 0) $args[] = $project_id;
    }
    $sql = "SELECT DISTINCT role FROM {$px}assignments WHERE " . implode(' AND ', $where) . " ORDER BY role ASC";
    $dbRoles  = $args ? $wpdb->get_col($wpdb->prepare($sql, ...$args)) : $wpdb->get_col($sql);
    $merged   = array_values(array_unique(array_merge($defaults, $dbRoles ?: [])));

    $out = array_map(function($r){
        return ['id'=>$r, 'name'=>ucfirst(str_replace('_',' ',$r))];
    }, $merged);

    wp_send_json($out);
});

/**
 * Alias para compatibilidade: algumas UIs pedem routespro_functions_list
 */
add_action('wp_ajax_routespro_functions_list', function () {
    do_action('wp_ajax_routespro_roles');
});

/**
 * LISTAR atribuições de uma rota (com detalhes de utilizador)
 */
add_action('wp_ajax_routespro_assignments_for_route', function () {
    if (!current_user_can('read')) wp_send_json([], 403);

    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $rid = absint($_GET['route_id'] ?? 0);
    if (!$rid) wp_send_json([], 200);

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT a.id,
               a.user_id,
               a.role,
               u.user_login,
               u.user_email,
               u.display_name
        FROM {$px}assignments a
        LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
        WHERE a.route_id = %d
        ORDER BY a.id ASC
    ", $rid), ARRAY_A);

    wp_send_json($rows ?: []);
});

/**
 * IDs de utilizadores atribuídos a uma rota (usado no front se só precisarmos dos IDs)
 */
add_action('wp_ajax_routespro_route_owner_ids', function () {
    if (!current_user_can('read')) wp_send_json([], 403);

    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $rid = absint($_GET['route_id'] ?? 0);
    if (!$rid) wp_send_json([], 200);

    $ids = $wpdb->get_col($wpdb->prepare("
        SELECT user_id FROM {$px}assignments
        WHERE route_id = %d ORDER BY id ASC
    ", $rid));

    wp_send_json($ids ?: []);
});

/**
 * ATRIBUIR rota a utilizadores (com função)
 *
 * Aceita formatos:
 *  (A) POST 'payload' JSON:
 *      { "route_id": 123, "assignments": [ {"user_id":12,"role":"comercial"}, ... ] }
 *  (B) POST 'assignments' JSON: [ {"user_id":12,"role":"..."} ]
 *      + POST 'route_id'
 *  (C) POST 'owners' CSV (legado) + 'route_id' → role padrão 'driver'
 */
add_action('wp_ajax_routespro_assign_route', function () {
    if (!current_user_can('edit_posts')) wp_send_json(['ok' => false, 'error' => 'forbidden'], 403);

    global $wpdb; $px = $wpdb->prefix . 'routespro_';

    $rid = absint($_POST['route_id'] ?? 0);
    $assignments = [];

    // (A) payload JSON completo
    if (!empty($_POST['payload'])) {
        $payload = json_decode(stripslashes((string)$_POST['payload']), true);
        if (is_array($payload)) {
            $rid = absint($payload['route_id'] ?? $rid);
            if (isset($payload['assignments']) && is_array($payload['assignments'])) {
                foreach ($payload['assignments'] as $row) {
                    $uid  = absint($row['user_id'] ?? 0);
                    $role = sanitize_text_field($row['role'] ?? 'driver');
                    if ($uid) $assignments[] = ['user_id' => $uid, 'role' => $role];
                }
            }
        }
    }

    // (B) assignments JSON + route_id separado
    if (!$assignments && !empty($_POST['assignments'])) {
        $json = json_decode(stripslashes((string)$_POST['assignments']), true);
        if (is_array($json)) {
            foreach ($json as $row) {
                $uid  = absint($row['user_id'] ?? 0);
                $role = sanitize_text_field($row['role'] ?? 'driver');
                if ($uid) $assignments[] = ['user_id' => $uid, 'role' => $role];
            }
        }
    }

    // (C) owners CSV legado
    if (!$assignments && !empty($_POST['owners'])) {
        $owners = array_filter(array_map('absint', explode(',', (string)$_POST['owners'])));
        foreach ($owners as $uid) { $assignments[] = ['user_id' => $uid, 'role' => 'driver']; }
    }

    if (!$rid) wp_send_json(['ok'=>false,'error'=>'missing route_id'], 400);

    // Limpa atuais
    $wpdb->delete($px.'assignments', ['route_id' => $rid]);

    // Insere novas atribuições
    $owner_user_id = 0;
    foreach ($assignments as $i => $as) {
        $wpdb->insert($px.'assignments', [
            'route_id' => $rid,
            'user_id'  => $as['user_id'],
            'role'     => $as['role'],
        ], ['%d','%d','%s']);

        if ($i === 0) $owner_user_id = (int)$as['user_id']; // primeiro vira owner por convenção
    }

    if ($owner_user_id) {
        $wpdb->update($px.'routes', ['owner_user_id' => $owner_user_id], ['id' => $rid], ['%d'], ['%d']);
    }

    wp_send_json(['ok' => true, 'count' => count($assignments)]);
});


add_action('wp_ajax_routespro_campaign_plan_preview', function () {
    \RoutesPro\Admin\CampaignLocations::ajax_plan_preview();
});
/**
 * UPLOAD de foto/prova (front)
 * - recebe ficheiro em $_FILES['file']
 * - retorna { ok, url, file } em sucesso
 * - exige login; (opcional) verifica nonce se passado
 */
add_action('wp_ajax_routespro_upload_proof', function () {
    if (!is_user_logged_in()) wp_send_json(['ok'=>false,'error'=>'forbidden'], 403);

    // (Opcional) valida nonce se enviares no front: routespro_upload_proof_nonce
    // if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'routespro_upload_proof')) {
    //     wp_send_json(['ok'=>false,'error'=>'bad_nonce'], 403);
    // }

    if (empty($_FILES['file'])) {
        wp_send_json(['ok'=>false,'error'=>'missing_file'], 400);
    }

    // Limitar tipos básicos de imagem (podes expandir)
    $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
    $filetype = $_FILES['file']['type'] ?? '';
    if ($filetype && !in_array($filetype, $allowed_types, true)) {
        wp_send_json(['ok'=>false,'error'=>'invalid_type'], 400);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    $overrides = ['test_form' => false, 'mimes' => null]; // usar mimes do WP
    $movefile  = wp_handle_upload($_FILES['file'], $overrides);

    if (!empty($movefile['error'])) {
        wp_send_json(['ok'=>false,'error'=>$movefile['error']], 500);
    }

    $url  = $movefile['url'];
    $file = $movefile['file'];

    // Opcional: criar attachment na Media Library:
    // require_once ABSPATH . 'wp-admin/includes/image.php';
    // $attach_id = wp_insert_attachment([
    //     'post_mime_type' => $movefile['type'],
    //     'post_title'     => wp_basename($file),
    //     'post_content'   => '',
    //     'post_status'    => 'inherit'
    // ], $file);
    // $attach_data = wp_generate_attachment_metadata($attach_id, $file);
    // wp_update_attachment_metadata($attach_id, $attach_data);

    wp_send_json([
        'ok'   => true,
        'url'  => esc_url_raw($url),
        'file' => $file,
        // 'attachment_id' => $attach_id ?? null,
    ]);
});

/**
 * Owners / membros relevantes para uma campanha, para uso no portal do cliente.
 */
add_action('wp_ajax_routespro_team_recipients', function () {
    if (!is_user_logged_in()) wp_send_json([], 403);

    $client_id = absint($_GET['client_id'] ?? 0);
    $project_id = absint($_GET['project_id'] ?? 0);
    $perm = \RoutesPro\Support\Permissions::assert_scope_or_error($client_id, $project_id);
    if (is_wp_error($perm)) wp_send_json(['message' => $perm->get_error_message()], 403);

    $users = \RoutesPro\Admin\Emails::get_team_recipients($client_id, $project_id);
    wp_send_json($users ?: []);
});

add_action('wp_ajax_routespro_get_client_team_senders', function () {
    if (!is_user_logged_in()) wp_send_json(['message' => 'Sem sessão ativa.'], 403);
    if (!empty($_GET['_wpnonce'])) check_ajax_referer('wp_rest', '_wpnonce');
    $client_id = absint($_GET['client_id'] ?? 0);
    $project_id = absint($_GET['project_id'] ?? 0);
    $perm = \RoutesPro\Support\Permissions::assert_scope_or_error($client_id, $project_id);
    if (is_wp_error($perm)) wp_send_json(['message' => $perm->get_error_message()], 403);
    $users = \RoutesPro\Admin\Emails::get_available_senders_for_user($client_id, $project_id, get_current_user_id());
    wp_send_json($users ?: []);
});

/**
 * Envio de email do cliente para membro da equipa, filtrado por campanha.
 */
add_action('wp_ajax_routespro_send_client_team_mail', function () {
    if (!is_user_logged_in()) wp_send_json(['message' => 'Sem sessão ativa.'], 403);
    check_ajax_referer('wp_rest', '_wpnonce');

    $client_id = absint($_POST['client_id'] ?? 0);
    $project_id = absint($_POST['project_id'] ?? 0);
    $sender_user_id = absint($_POST['sender_user_id'] ?? 0);
    $recipient_user_id = absint($_POST['recipient_user_id'] ?? 0);
    $message_kind = sanitize_key($_POST['message_kind'] ?? 'geral');
    $subject = sanitize_text_field($_POST['subject'] ?? '');
    $body = wp_kses_post($_POST['message'] ?? '');
    $priority = sanitize_text_field($_POST['priority'] ?? 'normal');
    $send_copy = !empty($_POST['send_copy']);

    if (!$project_id || !$sender_user_id || !$recipient_user_id || $subject === '' || trim(wp_strip_all_tags($body)) === '') {
        wp_send_json(['message' => 'Preenche campanha, owner remetente, owner destinatário, assunto e mensagem.'], 422);
    }

    $perm = \RoutesPro\Support\Permissions::assert_scope_or_error($client_id, $project_id);
    if (is_wp_error($perm)) wp_send_json(['message' => $perm->get_error_message()], 403);

    $allowed = \RoutesPro\Admin\Emails::get_team_recipients($client_id, $project_id);
    $allowedSenders = \RoutesPro\Admin\Emails::get_available_senders_for_user($client_id, $project_id, get_current_user_id());
    $recipient = null;
    $senderChoice = null;
    foreach ($allowed as $user) {
        if ((int) ($user['ID'] ?? 0) === $recipient_user_id) { $recipient = $user; }
    }
    foreach ($allowedSenders as $user) {
        if ($sender_user_id && (int) ($user['ID'] ?? 0) === $sender_user_id) { $senderChoice = $user; }
    }
    if (!$senderChoice && $sender_user_id) {
        $contextIds = array_fill_keys(\RoutesPro\Support\Permissions::get_associated_user_ids($client_id, $project_id), true);
        foreach ($allowed as $user) {
            $uid = (int) ($user['ID'] ?? 0);
            if ($uid === $sender_user_id && (!empty($contextIds[$uid]) || current_user_can('routespro_manage'))) {
                $senderChoice = $user;
                break;
            }
        }
    }
    if (!$senderChoice || empty($senderChoice['user_email'])) {
        wp_send_json(['message' => 'O owner remetente selecionado não está disponível para esta campanha.'], 422);
    }
    if (!$recipient || empty($recipient['user_email'])) {
        wp_send_json(['message' => 'O owner destinatário selecionado não está disponível para esta campanha.'], 422);
    }

    global $wpdb;
    $px = $wpdb->prefix . 'routespro_';
    $client = $client_id ? ($wpdb->get_row($wpdb->prepare("SELECT id,name,email FROM {$px}clients WHERE id=%d", $client_id), ARRAY_A) ?: []) : [];
    $project = $wpdb->get_row($wpdb->prepare("SELECT id,name,client_id FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A) ?: [];
    if (!$client_id && !empty($project['client_id'])) $client_id = (int) $project['client_id'];
    if (!$project) wp_send_json(['message' => 'Campanha inválida.'], 404);

    $current_user = wp_get_current_user();
    $sender = $senderChoice ? get_user_by('id', (int) $senderChoice['ID']) : $current_user;
    if (!$sender || !($sender instanceof \WP_User) || !$sender->exists()) $sender = $current_user;
    $dedupe_key = 'routespro_ctm_' . md5(get_current_user_id() . '|' . $project_id . '|' . $sender->ID . '|' . $recipient_user_id . '|' . strtolower($subject) . '|' . wp_strip_all_tags($body));
    if (get_transient($dedupe_key)) {
        wp_send_json(['ok' => true, 'message' => 'Mensagem já submetida há instantes. Evitámos duplicados.']);
    }
    set_transient($dedupe_key, 1, 30);

    $opts = \RoutesPro\Admin\Emails::get();
    $prelog_id = \RoutesPro\Admin\Emails::log_email([
        'email_type' => 'client_team_message',
        'context_key' => 'portal',
        'client_id' => $client_id,
        'project_id' => $project_id,
        'sender_user_id' => (int) ($sender->ID ?: get_current_user_id()),
        'recipient_user_id' => $recipient_user_id,
        'recipient_email' => sanitize_email((string) ($recipient['user_email'] ?? '')),
        'recipient_name' => (string) ($recipient['display_name'] ?? ''),
        'message_kind' => $message_kind,
        'subject' => '[Portal Cliente] ' . $subject,
        'body' => $body,
        'meta' => [
            'priority' => $priority,
            'copied_to_sender' => $send_copy,
            'sender_email' => (string) ($sender->user_email ?? ''),
            'selected_sender_user_id' => (int) ($sender->ID ?: 0),
            'selected_sender_email' => (string) ($sender->user_email ?? ''),
            'submitted_by_user_id' => get_current_user_id(),
            'submitted_by_email' => (string) ($current_user->user_email ?? ''),
            'workflow_status' => 'novo',
        ],
        'mail_result' => 'pending',
    ]);
    $message_app_url = \RoutesPro\Admin\Emails::get_message_app_url($prelog_id);
    $tokens = [
        '{client_name}' => (string) ($client['name'] ?? ''),
        '{project_name}' => (string) ($project['name'] ?? ''),
        '{user_name}' => (string) ($recipient['display_name'] ?? ''),
        '{message_kind}' => ucfirst($message_kind ?: 'geral'),
        '{priority}' => $priority,
        '{sender_name}' => (string) ($sender->display_name ?: $sender->user_login),
        '{sender_email}' => (string) ($sender->user_email ?? ''),
    ];

    $plain_body = "Olá {user_name},\n\nRecebeste uma nova mensagem do portal do cliente.\n\nCliente: {client_name}\nCampanha: {project_name}\nCategoria: {message_kind}\nPrioridade: {priority}\nEnviado por: {sender_name} <{sender_email}>\n\nMensagem:\n" . wp_strip_all_tags($body) . "\n\nCumprimentos,\nRoutesPro";
    $final_subject = '[Portal Cliente] ' . $subject;
    $final_body = strtr($plain_body, $tokens);
    $html_body = '<p>Olá <strong>' . esc_html((string) ($recipient['display_name'] ?? 'equipa')) . '</strong>,</p>'
        . '<p>Recebeste uma nova mensagem do portal do cliente.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:14px 0">'
        . '<tr><td style="padding:6px 0;color:#64748b">Cliente</td><td style="padding:6px 0"><strong>' . esc_html((string) ($client['name'] ?? '-')) . '</strong></td></tr>'
        . '<tr><td style="padding:6px 0;color:#64748b">Campanha</td><td style="padding:6px 0"><strong>' . esc_html((string) ($project['name'] ?? '-')) . '</strong></td></tr>'
        . '<tr><td style="padding:6px 0;color:#64748b">Categoria</td><td style="padding:6px 0">' . esc_html(ucfirst($message_kind ?: 'geral')) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#64748b">Prioridade</td><td style="padding:6px 0">' . esc_html($priority) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#64748b">Enviado por</td><td style="padding:6px 0">' . esc_html((string) ($sender->display_name ?: $sender->user_login)) . ' &lt;' . esc_html((string) ($sender->user_email ?? '')) . '&gt;</td></tr>'
        . '</table>'
        . '<div style="padding:14px;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc">' . \RoutesPro\Admin\Emails::format_body_html($body) . '</div>';

    $headers = [];
    if (!empty($opts['send_as_html'])) $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $fromEmail = sanitize_email((string) ($opts['from_email'] ?? ''));
    $fromName = wp_specialchars_decode((string) ($opts['from_name'] ?? get_bloginfo('name')), ENT_QUOTES);
    if ($fromEmail) {
        $headers[] = 'From: ' . ($fromName ?: get_bloginfo('name')) . ' <' . $fromEmail . '>';
    }
    $replyToEmail = sanitize_email((string) ($sender->user_email ?? ''));
    $replyToName  = wp_specialchars_decode((string) ($sender->display_name ?: $sender->user_login ?: ''), ENT_QUOTES);
    if ($replyToEmail) {
        $headers[] = 'Reply-To: ' . ($replyToName ?: $replyToEmail) . ' <' . $replyToEmail . '>';
    } elseif (!empty($opts['reply_to'])) {
        $headers[] = 'Reply-To: ' . sanitize_email((string) $opts['reply_to']);
    }

    $recipients = [sanitize_email((string) $recipient['user_email'])];
    $copy_email = sanitize_email((string) ($sender->user_email ?? ''));
    if ($send_copy && $copy_email) $recipients[] = $copy_email;
    $recipients = array_values(array_unique(array_filter($recipients)));

    $message_payload = !empty($opts['send_as_html'])
        ? \RoutesPro\Admin\Emails::render_message_html($final_subject, $html_body, [
            'brand_primary' => $opts['brand_primary'] ?? '',
            'footer_text' => $opts['footer_text'] ?? '',
            'button_label' => 'Abrir portal',
            'route_url' => $message_app_url,
        ])
        : $final_body;

    $sent = wp_mail($recipients, $final_subject, $message_payload, $headers);
    $preMeta = [
        'priority' => $priority,
        'copied_to_sender' => $send_copy,
        'sender_email' => (string) ($sender->user_email ?? ''),
        'selected_sender_user_id' => (int) ($sender->ID ?: 0),
        'selected_sender_email' => (string) ($sender->user_email ?? ''),
        'submitted_by_user_id' => get_current_user_id(),
        'submitted_by_email' => (string) ($current_user->user_email ?? ''),
        'workflow_status' => 'novo',
        'app_url' => $message_app_url,
    ];
    $wpdb->update($wpdb->prefix . 'routespro_email_logs', [
        'mail_result' => $sent ? 'sent' : 'failed',
        'meta_json' => wp_json_encode($preMeta),
    ], ['id' => $prelog_id], ['%s','%s'], ['%d']);
    $copy_email = sanitize_email((string) ($sender->user_email ?? ''));
    if ($send_copy && $copy_email && $copy_email !== (string) ($recipient['user_email'] ?? '')) {
        \RoutesPro\Admin\Emails::log_email([
            'email_type' => 'client_team_message',
            'context_key' => 'portal_copy',
            'client_id' => $client_id,
            'project_id' => $project_id,
            'sender_user_id' => (int) ($sender->ID ?: get_current_user_id()),
            'recipient_user_id' => (int) ($sender->ID ?: 0),
            'recipient_email' => $copy_email,
            'recipient_name' => (string) ($sender->display_name ?: $sender->user_login),
            'message_kind' => $message_kind,
            'subject' => $final_subject,
            'body' => $body,
            'meta' => array_merge($preMeta, ['copy_of_log_id' => $prelog_id]),
            'mail_result' => $sent ? 'sent' : 'failed',
        ]);
    }

    wp_send_json(['ok' => (bool) $sent, 'message' => $sent ? 'Mensagem enviada com sucesso.' : 'Falha ao enviar o email.']);
});


add_action('wp_ajax_routespro_get_team_messages', function () {
    if (!is_user_logged_in()) wp_send_json(['message' => 'Sem sessão ativa.'], 403);
    check_ajax_referer('wp_rest', '_wpnonce');
    $filters = [
        'client_id' => absint($_GET['client_id'] ?? 0),
        'project_id' => absint($_GET['project_id'] ?? 0),
        'status' => sanitize_text_field($_GET['status'] ?? ''),
        'message_id' => absint($_GET['message_id'] ?? 0),
    ];
    $rows = \RoutesPro\Admin\Emails::get_user_message_logs(get_current_user_id(), $filters);
    wp_send_json(['ok' => true, 'items' => $rows]);
});

add_action('wp_ajax_routespro_update_team_message', function () {
    if (!is_user_logged_in()) wp_send_json(['message' => 'Sem sessão ativa.'], 403);
    check_ajax_referer('wp_rest', '_wpnonce');
    global $wpdb;
    $table = $wpdb->prefix . 'routespro_email_logs';
    $log_id = absint($_POST['log_id'] ?? 0);
    $action = sanitize_key($_POST['message_action'] ?? '');
    if (!$log_id || !$action) wp_send_json(['message' => 'Pedido inválido.'], 422);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $log_id), ARRAY_A);
    if (!$row) wp_send_json(['message' => 'Mensagem não encontrada.'], 404);
    $uid = get_current_user_id();
    if ((int)($row['recipient_user_id'] ?? 0) !== $uid && (int)($row['sender_user_id'] ?? 0) !== $uid && !current_user_can('routespro_manage')) wp_send_json(['message' => 'Sem permissão para esta mensagem.'], 403);
    $meta = json_decode((string)($row['meta_json'] ?? ''), true);
    if (!is_array($meta)) $meta = [];
    if ($action === 'status') {
        $status = sanitize_text_field($_POST['status'] ?? 'novo');
        $meta['workflow_status'] = $status;
        if ($status === 'concluido') $meta['closed_at'] = current_time('mysql');
        $meta['last_action_at'] = current_time('mysql');
        $wpdb->update($table, ['meta_json' => wp_json_encode($meta)], ['id' => $log_id], ['%s'], ['%d']);
        wp_send_json(['ok' => true, 'message' => 'Estado atualizado.']);
    }
    if ($action === 'reply') {
        $reply = wp_kses_post($_POST['reply_message'] ?? '');
        if (trim(wp_strip_all_tags($reply)) === '') wp_send_json(['message' => 'Escreve uma resposta.'], 422);
        $target_user = get_user_by('id', (int)($row['sender_user_id'] ?? 0));
        $target_email = sanitize_email((string)($target_user->user_email ?? ''));
        if ($target_email === '') {
            $submitted = get_userdata((int)($meta['submitted_by_user_id'] ?? 0));
            $target_email = sanitize_email((string)($submitted->user_email ?? ''));
        }
        if ($target_email === '') wp_send_json(['message' => 'Não foi possível encontrar o email de resposta.'], 422);
        $sender = wp_get_current_user();
        $opts = \RoutesPro\Admin\Emails::get();
        $subject = '[Operação] Re: ' . sanitize_text_field((string)($row['subject'] ?? 'Mensagem'));
        $bodyHtml = '<p>Olá,</p><p>Recebeste uma resposta operacional a uma mensagem do portal.</p><div style="padding:14px;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc">' . \RoutesPro\Admin\Emails::format_body_html($reply) . '</div>';
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $fromEmail = sanitize_email((string)($opts['from_email'] ?? ''));
        $fromName = wp_specialchars_decode((string)($opts['from_name'] ?? get_bloginfo('name')), ENT_QUOTES);
        if ($fromEmail) $headers[] = 'From: ' . ($fromName ?: get_bloginfo('name')) . ' <' . $fromEmail . '>';
        if (!empty($sender->user_email)) $headers[] = 'Reply-To: ' . wp_specialchars_decode((string)($sender->display_name ?: $sender->user_login), ENT_QUOTES) . ' <' . sanitize_email((string)$sender->user_email) . '>';
        $payload = \RoutesPro\Admin\Emails::render_message_html($subject, $bodyHtml, [
            'brand_primary' => $opts['brand_primary'] ?? '',
            'footer_text' => $opts['footer_text'] ?? '',
            'button_label' => 'Abrir mensagem',
            'route_url' => \RoutesPro\Admin\Emails::get_message_app_url($log_id),
        ]);
        $sent = wp_mail($target_email, $subject, $payload, $headers);
        $meta['workflow_status'] = $sent ? 'respondido' : ($meta['workflow_status'] ?? 'novo');
        $meta['last_reply_at'] = current_time('mysql');
        $meta['last_reply_by_user_id'] = $uid;
        $meta['last_reply_excerpt'] = wp_trim_words(wp_strip_all_tags($reply), 18, '...');
        $wpdb->update($table, ['meta_json' => wp_json_encode($meta)], ['id' => $log_id], ['%s'], ['%d']);
        \RoutesPro\Admin\Emails::log_email([
            'email_type' => 'client_team_message_reply',
            'context_key' => 'routespro_app',
            'client_id' => (int)($row['client_id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'route_id' => (int)($row['route_id'] ?? 0),
            'sender_user_id' => $uid,
            'recipient_user_id' => (int)($row['sender_user_id'] ?? 0),
            'recipient_email' => $target_email,
            'recipient_name' => (string)($target_user->display_name ?? $target_email),
            'message_kind' => 'reply',
            'subject' => $subject,
            'body' => $reply,
            'meta' => ['parent_log_id' => $log_id],
            'mail_result' => $sent ? 'sent' : 'failed',
        ]);
        wp_send_json(['ok' => (bool)$sent, 'message' => $sent ? 'Resposta enviada com sucesso.' : 'Falha ao enviar a resposta.']);
    }
    wp_send_json(['message' => 'Ação inválida.'], 422);
});

add_action('wp_ajax_routespro_front_clients', function () {
    if (!is_user_logged_in()) wp_send_json([]);
    check_ajax_referer('wp_rest', '_wpnonce');
    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $rows = $wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
    $rows = \RoutesPro\Support\Permissions::filter_clients($rows);
    wp_send_json(array_values($rows));
});

add_action('wp_ajax_routespro_front_projects', function () {
    if (!is_user_logged_in()) wp_send_json([]);
    check_ajax_referer('wp_rest', '_wpnonce');
    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $client_id = absint($_GET['client_id'] ?? 0);
    $where = $client_id ? $wpdb->prepare(" WHERE client_id=%d", $client_id) : '';
    $rows = $wpdb->get_results("SELECT id,client_id,name FROM {$px}projects" . $where . " ORDER BY name ASC", ARRAY_A) ?: [];
    $rows = \RoutesPro\Support\Permissions::filter_projects($rows);
    wp_send_json(array_values($rows));
});
