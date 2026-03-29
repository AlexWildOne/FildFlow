<?php
namespace RoutesPro\Support;

use WP_REST_Request;
use WP_Error;

if (!defined('ABSPATH')) exit;

class Permissions {
    public static function get_assignable_users(int $client_id = 0, int $project_id = 0, array $fields = ['ID','display_name','user_email','user_login']): array {
        return AssignmentMatrix::get_assignable_users($client_id, $project_id, $fields);
    }

    public static function can_access_route(int $route_id, ?int $user_id = null): bool {
        global $wpdb;
        $uid = $user_id ?: get_current_user_id();
        if (!$uid || $route_id <= 0) return false;
        if (self::is_manager($uid)) return true;
        $px = $wpdb->prefix . 'routespro_';
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, client_id, project_id, owner_user_id FROM {$px}routes WHERE id=%d", $route_id), ARRAY_A);
        if (!$row) return false;
        if ((int)($row['owner_user_id'] ?? 0) === $uid) return true;
        $assigned = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$px}assignments WHERE route_id=%d AND user_id=%d AND is_active=1", $route_id, $uid));
        if ($assigned > 0) return true;
        return !is_wp_error(self::assert_scope_or_error((int)($row['client_id'] ?? 0), (int)($row['project_id'] ?? 0), $uid));
    }

    public static function is_manager(?int $user_id = null): bool {
        if ($user_id && $user_id !== get_current_user_id()) {
            $user = get_userdata($user_id);
            return $user ? user_can($user, 'routespro_manage') : false;
        }
        return current_user_can('routespro_manage');
    }

    public static function can_access_front(?int $user_id = null): bool {
        $uid = $user_id ?: get_current_user_id();
        if (!$uid) return false;
        if (self::is_manager($uid)) return true;
        if (user_can($uid, 'routespro_execute')) return true;
        $scope = self::get_scope($uid);
        return !empty($scope['client_ids']) || !empty($scope['project_ids']);
    }

    private static function parse_meta($json): array {
        if (is_array($json)) return $json;
        if (!is_string($json) || trim($json) === '') return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function extract_user_ids(array $meta): array {
        $candidates = [
            $meta['associated_user_ids'] ?? null,
            $meta['user_ids'] ?? null,
            $meta['users'] ?? null,
            $meta['assigned_users'] ?? null,
        ];
        $out = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $candidate = preg_split('/\s*,\s*/', trim($candidate));
            }
            if (!is_array($candidate)) continue;
            foreach ($candidate as $value) {
                if (is_array($value)) $value = $value['user_id'] ?? $value['id'] ?? 0;
                $id = absint($value);
                if ($id) $out[$id] = $id;
            }
        }
        ksort($out);
        return array_values($out);
    }



    public static function get_associated_user_ids(int $client_id = 0, int $project_id = 0): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $ids = [];
        if ($client_id > 0) {
            $meta_json = $wpdb->get_var($wpdb->prepare("SELECT meta_json FROM {$px}clients WHERE id=%d", $client_id));
            $meta = self::parse_meta($meta_json);
            foreach (self::extract_user_ids($meta) as $uid) $ids[$uid] = $uid;
        }
        if ($project_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT client_id, meta_json FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A);
            if ($row) {
                $projectClientId = (int)($row['client_id'] ?? 0);
                if (!$client_id && $projectClientId > 0) {
                    $meta_json = $wpdb->get_var($wpdb->prepare("SELECT meta_json FROM {$px}clients WHERE id=%d", $projectClientId));
                    $meta = self::parse_meta($meta_json);
                    foreach (self::extract_user_ids($meta) as $uid) $ids[$uid] = $uid;
                }
                $meta = self::parse_meta($row['meta_json'] ?? '');
                $projectUsers = self::extract_user_ids($meta);
                if (!empty($projectUsers)) {
                    $ids = [];
                    foreach ($projectUsers as $uid) $ids[$uid] = $uid;
                }
            }
        }
        ksort($ids);
        return array_values($ids);
    }

    public static function get_associated_users(int $client_id = 0, int $project_id = 0, array $fields = ['ID','display_name','user_email','user_login']): array {
        $user_ids = self::get_associated_user_ids($client_id, $project_id);
        if (empty($user_ids)) return [];
        $users = get_users([
            'include' => $user_ids,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => $fields,
        ]);
        return is_array($users) ? $users : [];
    }

    public static function get_scope(?int $user_id = null): array {
        global $wpdb;
        $uid = $user_id ?: get_current_user_id();
        $scope = [
            'is_manager' => false,
            'client_ids' => [],
            'project_ids' => [],
            'route_ids' => [],
            'role' => 'restricted',
        ];
        if (!$uid) return $scope;
        if (self::is_manager($uid)) {
            $scope['is_manager'] = true;
            $scope['role'] = 'manager';
            return $scope;
        }

        $px = $wpdb->prefix . 'routespro_';
        $client_ids = [];
        $project_ids = [];
        $route_ids = [];

        $clients = $wpdb->get_results("SELECT id, meta_json FROM {$px}clients", ARRAY_A) ?: [];
        foreach ($clients as $client) {
            $meta = self::parse_meta($client['meta_json'] ?? '');
            if (in_array($uid, self::extract_user_ids($meta), true)) {
                $client_ids[(int)$client['id']] = (int)$client['id'];
            }
        }

        $projects = $wpdb->get_results("SELECT id, client_id, meta_json FROM {$px}projects", ARRAY_A) ?: [];
        foreach ($projects as $project) {
            $pid = (int)($project['id'] ?? 0);
            $cid = (int)($project['client_id'] ?? 0);
            $meta = self::parse_meta($project['meta_json'] ?? '');
            if (in_array($uid, self::extract_user_ids($meta), true)) {
                $project_ids[$pid] = $pid;
                if ($cid) $client_ids[$cid] = $cid;
            }
        }

        $projectAssignments = $wpdb->get_results($wpdb->prepare(
            "SELECT pa.project_id, p.client_id
             FROM {$px}project_assignments pa
             INNER JOIN {$px}projects p ON p.id = pa.project_id
             WHERE pa.user_id = %d AND pa.is_active = 1",
            $uid
        ), ARRAY_A) ?: [];
        foreach ($projectAssignments as $row) {
            $pid = (int)($row['project_id'] ?? 0);
            $cid = (int)($row['client_id'] ?? 0);
            if ($pid) $project_ids[$pid] = $pid;
            if ($cid) $client_ids[$cid] = $cid;
        }

        $routeAssignments = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT r.id AS route_id, r.client_id, r.project_id
             FROM {$px}routes r
             LEFT JOIN {$px}assignments a ON a.route_id = r.id AND a.is_active = 1
             WHERE r.owner_user_id = %d OR a.user_id = %d",
            $uid, $uid
        ), ARRAY_A) ?: [];
        foreach ($routeAssignments as $row) {
            $rid = (int)($row['route_id'] ?? 0);
            $pid = (int)($row['project_id'] ?? 0);
            $cid = (int)($row['client_id'] ?? 0);
            if ($rid) $route_ids[$rid] = $rid;
            if ($pid) $project_ids[$pid] = $pid;
            if ($cid) $client_ids[$cid] = $cid;
        }

        ksort($client_ids);
        ksort($project_ids);
        ksort($route_ids);
        $scope['client_ids'] = array_values($client_ids);
        $scope['project_ids'] = array_values($project_ids);
        $scope['route_ids'] = array_values($route_ids);
        return $scope;
    }

    public static function filter_clients(array $clients, ?int $user_id = null): array {
        $scope = self::get_scope($user_id);
        if ($scope['is_manager']) return $clients;
        $allowed = array_fill_keys($scope['client_ids'], true);
        return array_values(array_filter($clients, function($row) use ($allowed){ return !empty($allowed[(int)($row['id'] ?? 0)]); }));
    }

    public static function filter_projects(array $projects, ?int $user_id = null): array {
        $scope = self::get_scope($user_id);
        if ($scope['is_manager']) return $projects;
        $allowedProjects = array_fill_keys($scope['project_ids'], true);
        return array_values(array_filter($projects, function($row) use ($allowedProjects){
            $pid = (int)($row['id'] ?? 0);
            return !empty($allowedProjects[$pid]);
        }));
    }

    public static function is_allowed_client(int $client_id, ?int $user_id = null): bool {
        if ($client_id <= 0) return true;
        $scope = self::get_scope($user_id);
        return $scope['is_manager'] || in_array($client_id, $scope['client_ids'], true);
    }

    public static function is_allowed_project(int $project_id, ?int $user_id = null): bool {
        if ($project_id <= 0) return true;
        $scope = self::get_scope($user_id);
        return $scope['is_manager'] || in_array($project_id, $scope['project_ids'], true);
    }

    public static function sanitize_scope_selection(int $client_id, int $project_id, ?int $user_id = null): array {
        $scope = self::get_scope($user_id);
        if ($scope['is_manager']) return [$client_id, $project_id];
        if ($client_id && !in_array($client_id, $scope['client_ids'], true)) $client_id = 0;
        if ($project_id && !in_array($project_id, $scope['project_ids'], true)) $project_id = 0;
        return [$client_id, $project_id];
    }

    public static function assert_scope_or_error(int $client_id, int $project_id, ?int $user_id = null) {
        if ($client_id && !self::is_allowed_client($client_id, $user_id)) {
            return new WP_Error('forbidden', 'Sem acesso ao cliente selecionado.', ['status' => 403]);
        }
        if ($project_id && !self::is_allowed_project($project_id, $user_id)) {
            return new WP_Error('forbidden', 'Sem acesso à campanha selecionada.', ['status' => 403]);
        }
        return true;
    }

    public static function scope_sql(string $routeAlias = 'r', ?string $clientColumn = null, ?string $projectColumn = null, ?int $user_id = null): array {
        $scope = self::get_scope($user_id);
        if ($scope['is_manager']) return ['1=1', []];
        $clientColumn = $clientColumn ?: ($routeAlias ? $routeAlias . '.client_id' : 'client_id');
        $projectColumn = $projectColumn ?: ($routeAlias ? $routeAlias . '.project_id' : 'project_id');
        $args = [];
        $clientIds = array_values(array_filter(array_map('absint', (array)($scope['client_ids'] ?? []))));
        $projectIds = array_values(array_filter(array_map('absint', (array)($scope['project_ids'] ?? []))));

        if (!empty($projectIds)) {
            $parts = [];
            if (!empty($clientIds)) {
                $parts[] = $clientColumn . ' IN (' . implode(',', array_fill(0, count($clientIds), '%d')) . ')';
                $args = array_merge($args, $clientIds);
            }
            $parts[] = $projectColumn . ' IN (' . implode(',', array_fill(0, count($projectIds), '%d')) . ')';
            $args = array_merge($args, $projectIds);
            return ['(' . implode(' AND ', $parts) . ')', $args];
        }

        if (!empty($clientIds)) {
            return [$clientColumn . ' IN (' . implode(',', array_fill(0, count($clientIds), '%d')) . ')', $clientIds];
        }

        return ['1=0', []];
    }
}

