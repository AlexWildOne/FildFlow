<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class AssignmentMatrix {
    private static function decode_meta($json): array {
        if (is_array($json)) return $json;
        if (!is_string($json) || trim($json) === '') return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function extract_ids_from_meta(array $meta): array {
        $values = $meta['associated_user_ids'] ?? ($meta['user_ids'] ?? ($meta['assigned_users'] ?? []));
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

    public static function get_assignable_user_ids(int $client_id = 0, int $project_id = 0): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $ids = [];

        if ($client_id > 0) {
            $meta = self::decode_meta((string)$wpdb->get_var($wpdb->prepare("SELECT meta_json FROM {$px}clients WHERE id=%d", $client_id)));
            foreach (self::extract_ids_from_meta($meta) as $uid) $ids[$uid] = $uid;
        }

        if ($project_id > 0) {
            $project = $wpdb->get_row($wpdb->prepare("SELECT client_id, meta_json FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A);
            if ($project) {
                $projectClientId = (int)($project['client_id'] ?? 0);
                if (!$client_id && $projectClientId > 0) $client_id = $projectClientId;
                $meta = self::decode_meta((string)($project['meta_json'] ?? ''));
                foreach (self::extract_ids_from_meta($meta) as $uid) $ids[$uid] = $uid;
            }
            $rows = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$px}project_assignments WHERE project_id=%d AND is_active=1", $project_id)) ?: [];
            foreach ($rows as $uid) { $uid = absint($uid); if ($uid) $ids[$uid] = $uid; }
        }

        if ($client_id > 0) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pa.user_id FROM {$px}project_assignments pa INNER JOIN {$px}projects p ON p.id = pa.project_id WHERE p.client_id=%d AND pa.is_active=1",
                $client_id
            )) ?: [];
            foreach ($rows as $uid) { $uid = absint($uid); if ($uid) $ids[$uid] = $uid; }
        }

        ksort($ids);
        return array_values($ids);
    }

    public static function get_assignable_users(int $client_id = 0, int $project_id = 0, array $fields = ['ID','display_name','user_email','user_login']): array {
        $user_ids = self::get_assignable_user_ids($client_id, $project_id);
        if (empty($user_ids)) return [];
        $users = get_users([
            'include' => $user_ids,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => $fields,
        ]);
        return is_array($users) ? $users : [];
    }

    public static function sync_route_owner_assignment(int $route_id, int $owner_user_id, string $role = 'owner'): void {
        global $wpdb;
        if ($route_id <= 0 || $owner_user_id <= 0) return;
        $px = $wpdb->prefix . 'routespro_';
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$px}assignments WHERE route_id=%d AND user_id=%d",
            $route_id, $owner_user_id
        ));
        if ($exists > 0) {
            $wpdb->update(
                "{$px}assignments",
                ['role' => sanitize_text_field($role), 'is_active' => 1],
                ['route_id' => $route_id, 'user_id' => $owner_user_id],
                ['%s','%d'],
                ['%d','%d']
            );
        } else {
            $wpdb->insert(
                "{$px}assignments",
                ['route_id' => $route_id, 'user_id' => $owner_user_id, 'role' => sanitize_text_field($role), 'is_active' => 1],
                ['%d','%d','%s','%d']
            );
        }
    }
}
