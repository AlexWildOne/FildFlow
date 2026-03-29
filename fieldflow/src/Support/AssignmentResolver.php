<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class AssignmentResolver {
    private static function decode_meta($json): array {
        if (is_array($json)) return $json;
        if (!is_string($json) || trim($json) === '') return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function extract_ids(array $values): array {
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

    public static function get_client_user_ids(int $client_id): array {
        global $wpdb;
        if ($client_id <= 0) return [];
        $px = $wpdb->prefix . 'routespro_';
        $meta = self::decode_meta((string)$wpdb->get_var($wpdb->prepare("SELECT meta_json FROM {$px}clients WHERE id=%d", $client_id)));
        return self::extract_ids($meta['associated_user_ids'] ?? ($meta['user_ids'] ?? []));
    }

    public static function save_client_user_ids(int $client_id, array $user_ids): void {
        global $wpdb;
        if ($client_id <= 0) return;
        $px = $wpdb->prefix . 'routespro_';
        $meta = self::decode_meta((string)$wpdb->get_var($wpdb->prepare("SELECT meta_json FROM {$px}clients WHERE id=%d", $client_id)));
        $ids = self::extract_ids($user_ids);
        $meta['associated_user_ids'] = $ids;
        $meta['user_ids'] = $ids;
        $wpdb->update("{$px}clients", ['meta_json' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)], ['id' => $client_id], ['%s'], ['%d']);
    }

    public static function get_project_context(int $project_id): array {
        global $wpdb;
        if ($project_id <= 0) return [];
        $px = $wpdb->prefix . 'routespro_';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A);
        if (!$row) return [];
        $meta = self::decode_meta((string)($row['meta_json'] ?? ''));
        $row['associated_user_ids'] = self::extract_ids($meta['associated_user_ids'] ?? ($meta['user_ids'] ?? []));
        $row['owners'] = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, role, is_active FROM {$px}project_assignments WHERE project_id=%d ORDER BY is_active DESC, user_id ASC",
            $project_id
        ), ARRAY_A) ?: [];
        return $row;
    }

    public static function save_project_assignments(int $project_id, array $associated_user_ids, array $owner_user_ids, string $owner_role = 'owner'): void {
        global $wpdb;
        if ($project_id <= 0) return;
        $px = $wpdb->prefix . 'routespro_';
        $project = $wpdb->get_row($wpdb->prepare("SELECT meta_json FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A);
        if (!$project) return;
        $meta = self::decode_meta((string)($project['meta_json'] ?? ''));
        $associated = self::extract_ids($associated_user_ids);
        $meta['associated_user_ids'] = $associated;
        $meta['user_ids'] = $associated;
        $wpdb->update("{$px}projects", ['meta_json' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)], ['id' => $project_id], ['%s'], ['%d']);

        $owners = self::extract_ids($owner_user_ids);
        $wpdb->delete("{$px}project_assignments", ['project_id' => $project_id], ['%d']);
        foreach ($owners as $uid) {
            $wpdb->insert("{$px}project_assignments", [
                'project_id' => $project_id,
                'user_id' => $uid,
                'role' => sanitize_text_field($owner_role ?: 'owner'),
                'is_active' => 1,
            ], ['%d','%d','%s','%d']);
        }
    }

    public static function get_route_context(int $route_id): array {
        global $wpdb;
        if ($route_id <= 0) return [];
        $px = $wpdb->prefix . 'routespro_';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}routes WHERE id=%d", $route_id), ARRAY_A);
        if (!$row) return [];
        $row['assignments'] = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, role, is_active FROM {$px}assignments WHERE route_id=%d ORDER BY is_active DESC, user_id ASC",
            $route_id
        ), ARRAY_A) ?: [];
        return $row;
    }

    public static function save_route_assignments(int $route_id, int $owner_user_id, array $team_user_ids, string $team_role = 'operacional'): void {
        global $wpdb;
        if ($route_id <= 0) return;
        $px = $wpdb->prefix . 'routespro_';
        $owner_user_id = absint($owner_user_id);
        $team = self::extract_ids($team_user_ids);
        if ($owner_user_id && !in_array($owner_user_id, $team, true)) $team[] = $owner_user_id;
        $team = self::extract_ids($team);
        $team_role = sanitize_text_field($team_role ?: 'operacional');

        $route = $wpdb->get_row($wpdb->prepare("SELECT meta_json FROM {$px}routes WHERE id=%d", $route_id), ARRAY_A);
        $meta = self::decode_meta((string)($route['meta_json'] ?? ''));
        $meta['default_team_role'] = $team_role;

        $wpdb->update("{$px}routes", [
            'owner_user_id' => $owner_user_id ?: null,
            'meta_json' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
        ], ['id' => $route_id], ['%d','%s'], ['%d']);
        $wpdb->delete("{$px}assignments", ['route_id' => $route_id], ['%d']);

        if ($owner_user_id) {
            $wpdb->insert("{$px}assignments", [
                'route_id' => $route_id,
                'user_id' => $owner_user_id,
                'role' => 'owner',
                'is_active' => 1,
            ], ['%d','%d','%s','%d']);
        }
        foreach ($team as $uid) {
            if ($uid === $owner_user_id) continue;
            $wpdb->insert("{$px}assignments", [
                'route_id' => $route_id,
                'user_id' => $uid,
                'role' => $team_role,
                'is_active' => 1,
            ], ['%d','%d','%s','%d']);
        }
    }

    public static function get_form_bindings(array $filters = []): array {
        global $wpdb;
        $table = \RoutesPro\Forms\BindingResolver::table();
        $forms = \RoutesPro\Forms\Forms::table();
        $px = $wpdb->prefix . 'routespro_';
        $sql = "SELECT b.*, f.title AS form_title, c.name AS client_name, p.name AS project_name, r.date AS route_date, loc.name AS location_name
                FROM {$table} b
                INNER JOIN {$forms} f ON f.id=b.form_id
                LEFT JOIN {$px}clients c ON c.id=b.client_id
                LEFT JOIN {$px}projects p ON p.id=b.project_id
                LEFT JOIN {$px}routes r ON r.id=b.route_id
                LEFT JOIN {$px}locations loc ON loc.id=b.location_id
                WHERE 1=1";
        $args = [];
        foreach (['client_id','project_id','route_id','form_id'] as $field) {
            $value = absint($filters[$field] ?? 0);
            if ($value) { $sql .= " AND b.{$field}=%d"; $args[] = $value; }
        }
        $sql .= " ORDER BY b.priority DESC, b.id DESC";
        return $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
    }

    public static function get_overview_counts(): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        return [
            'clients' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$px}clients"),
            'projects' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$px}projects"),
            'routes' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$px}routes"),
            'form_bindings' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$px}form_bindings"),
            'project_assignments' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$px}project_assignments WHERE is_active=1"),
            'route_assignments' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$px}assignments WHERE is_active=1"),
        ];
    }
}
