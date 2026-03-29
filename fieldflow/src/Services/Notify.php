<?php
namespace RoutesPro\Services;

class Notify {
    private static function build_route_context(array $route, array $client, ?array $project, string $assignee_name, string $assignee_email, int $stops, string $changes, string $stops_list_html): array {
        $routeId = (int)($route['id'] ?? 0);
        return [
            '{route_id}'     => (string) $routeId,
            '{date}'         => (string) ($route['date'] ?? ''),
            '{client_name}'  => (string) ($client['name'] ?? ''),
            '{project_name}' => (string) ($project['name'] ?? ''),
            '{user_name}'    => (string) $assignee_name,
            '{user_email}'   => (string) $assignee_email,
            '{client_email}' => (string) ($client['email'] ?? ''),
            '{stops}'        => (string) $stops,
            '{changes}'      => (string) $changes,
            '{route_status}' => (string) ($route['status'] ?? ''),
            '{route_url}'    => admin_url('admin.php?page=routespro-routes&edit='.$routeId),
            '{stops_list}'   => $stops_list_html,
        ];
    }

    private static function collect_stops_list(int $route_id): string {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT rs.seq, rs.status, rs.planned_arrival, l.name, l.city
             FROM {$px}route_stops rs
             LEFT JOIN {$px}locations l ON l.id = rs.location_id
             WHERE rs.route_id=%d
             ORDER BY rs.seq ASC, rs.id ASC
             LIMIT 25",
            $route_id
        ), ARRAY_A) ?: [];
        if (!$rows) return '';
        $html = '<ul style="margin:0;padding-left:18px">';
        foreach ($rows as $row) {
            $parts = [];
            $parts[] = esc_html((string)($row['name'] ?: 'Paragem'));
            if (!empty($row['city'])) $parts[] = esc_html((string)$row['city']);
            if (!empty($row['planned_arrival'])) {
                $parts[] = esc_html(mysql2date('H:i', (string)$row['planned_arrival'], false));
            }
            if (!empty($row['status'])) $parts[] = esc_html((string)$row['status']);
            $html .= '<li>'.implode(' · ', array_filter($parts)).'</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    public static function route_event($route_id, $type='updated', $changes=''){
        $opts = \RoutesPro\Admin\Emails::get();
        if (($type==='completed' && empty($opts['on_completed'])) || ($type==='updated' && empty($opts['on_updated']))) return;

        global $wpdb;
        $px = $wpdb->prefix.'routespro_';
        $route = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}routes WHERE id=%d", $route_id), ARRAY_A);
        if (!$route) return;

        $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}clients WHERE id=%d", $route['client_id']), ARRAY_A) ?: [];
        $project = !empty($route['project_id'])
            ? ($wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}projects WHERE id=%d", $route['project_id']), ARRAY_A) ?: [])
            : [];

        $assignee_email = '';
        $assignee_name = '';
        $uid = intval($route['owner_user_id'] ?? 0);
        if ($uid) {
            $u = get_userdata($uid);
            if ($u) {
                $assignee_email = (string) $u->user_email;
                $assignee_name = (string) $u->display_name;
            }
        }
        if (!$assignee_email) {
            $uid2 = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$px}assignments WHERE route_id=%d ORDER BY id ASC LIMIT 1", $route_id));
            if ($uid2) {
                $u2 = get_userdata($uid2);
                if ($u2) {
                    $assignee_email = (string) $u2->user_email;
                    $assignee_name = (string) $u2->display_name;
                }
            }
        }

        $stops = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$px}route_stops WHERE route_id=%d", $route_id));
        $stopsListHtml = self::collect_stops_list((int)$route_id);
        $context = self::build_route_context($route, $client, $project, $assignee_name, $assignee_email, $stops, (string)$changes, $stopsListHtml);

        $tpl = \RoutesPro\Admin\Emails::get_project_template((int)($route['project_id'] ?? 0), $opts);
        $subjectTpl = $type==='completed' ? ($tpl['subject_completed'] ?? '') : ($tpl['subject_updated'] ?? '');
        $bodyTpl    = $type==='completed' ? ($tpl['body_completed'] ?? '')    : ($tpl['body_updated'] ?? '');
        $subject = \RoutesPro\Admin\Emails::apply_placeholders((string)$subjectTpl, $context);
        $body    = \RoutesPro\Admin\Emails::apply_placeholders((string)$bodyTpl, $context);

        $to = [];
        if (!empty($opts['to_client']) && !empty($client['email'])) $to[] = (string)$client['email'];
        if (!empty($opts['to_collaborator']) && !empty($assignee_email)) $to[] = (string)$assignee_email;
        if (!empty($opts['extra_emails'])) {
            foreach (explode(',', (string)$opts['extra_emails']) as $e) {
                $e = sanitize_email(trim($e));
                if ($e) $to[] = $e;
            }
        }
        $to = array_values(array_unique(array_filter($to)));
        if (!$to) return;

        $headers = [];
        if (!empty($opts['send_as_html'])) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }
        $fromEmail = sanitize_email((string)($opts['from_email'] ?? ''));
        $fromName  = wp_specialchars_decode((string)($opts['from_name'] ?? ''), ENT_QUOTES);
        if ($fromEmail) {
            $headers[] = 'From: '.($fromName ?: get_bloginfo('name')).' <'.$fromEmail.'>';
        }
        $replyTo = sanitize_email((string)($opts['reply_to'] ?? ''));
        if ($replyTo) {
            $headers[] = 'Reply-To: '.$replyTo;
        }

        $message = !empty($opts['send_as_html'])
            ? \RoutesPro\Admin\Emails::render_message_html($subject, $body, [
                'brand_primary' => $opts['brand_primary'] ?? '',
                'footer_text'   => $opts['footer_text'] ?? '',
                'button_label'  => $opts['button_label'] ?? 'Abrir rota',
                'route_url'     => $context['{route_url}'] ?? '',
            ])
            : wp_strip_all_tags($body);

        $sent = wp_mail($to, $subject, $message, $headers);
        foreach ($to as $recipientEmail) {
            \RoutesPro\Admin\Emails::log_email([
                'email_type' => 'route_notification',
                'context_key' => $type,
                'client_id' => (int) ($route['client_id'] ?? 0),
                'project_id' => (int) ($route['project_id'] ?? 0),
                'route_id' => (int) $route_id,
                'sender_user_id' => get_current_user_id(),
                'recipient_user_id' => ($recipientEmail === $assignee_email && !empty($uid)) ? (int) $uid : 0,
                'recipient_email' => (string) $recipientEmail,
                'recipient_name' => ($recipientEmail === $assignee_email ? (string) $assignee_name : ''),
                'message_kind' => $type,
                'subject' => $subject,
                'body' => $body,
                'meta' => [
                    'route_status' => (string) ($route['status'] ?? ''),
                    'stops' => $stops,
                    'to_client' => in_array((string) ($client['email'] ?? ''), $to, true),
                ],
                'mail_result' => $sent ? 'sent' : 'failed',
            ]);
        }
    }
}
