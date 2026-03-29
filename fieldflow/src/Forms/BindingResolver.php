<?php
namespace RoutesPro\Forms;
use RoutesPro\Support\Permissions;
if (!defined('ABSPATH')) exit;

class BindingResolver {
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'routespro_form_bindings';
    }

    public static function get_context(array $args = []): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $context = [
            'client_id' => absint($args['client_id'] ?? 0),
            'project_id' => absint($args['project_id'] ?? 0),
            'route_id' => absint($args['route_id'] ?? 0),
            'stop_id' => absint($args['stop_id'] ?? 0),
            'location_id' => absint($args['location_id'] ?? 0),
        ];

        if ($context['stop_id']) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT rs.route_id, rs.location_id, r.client_id, r.project_id
                 FROM {$px}route_stops rs
                 LEFT JOIN {$px}routes r ON r.id = rs.route_id
                 WHERE rs.id = %d LIMIT 1",
                $context['stop_id']
            ), ARRAY_A);
            if ($row) {
                $context['route_id'] = $context['route_id'] ?: (int) ($row['route_id'] ?? 0);
                $context['location_id'] = $context['location_id'] ?: (int) ($row['location_id'] ?? 0);
                $context['client_id'] = $context['client_id'] ?: (int) ($row['client_id'] ?? 0);
                $context['project_id'] = $context['project_id'] ?: (int) ($row['project_id'] ?? 0);
            }
        }

        if ($context['route_id'] && (!$context['client_id'] || !$context['project_id'])) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT client_id, project_id FROM {$px}routes WHERE id = %d LIMIT 1",
                $context['route_id']
            ), ARRAY_A);
            if ($row) {
                $context['client_id'] = $context['client_id'] ?: (int) ($row['client_id'] ?? 0);
                $context['project_id'] = $context['project_id'] ?: (int) ($row['project_id'] ?? 0);
            }
        }

        if (!array_filter($context) && is_user_logged_in()) {
            $uid = get_current_user_id();
            $today = current_time('Y-m-d');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT r.id AS route_id, r.client_id, r.project_id
                 FROM {$px}routes r
                 LEFT JOIN {$px}assignments a ON a.route_id = r.id AND a.user_id = %d AND a.is_active = 1
                 WHERE (r.owner_user_id = %d OR a.user_id = %d OR r.project_id IN (SELECT pa.project_id FROM {$px}project_assignments pa WHERE pa.user_id = %d AND pa.is_active = 1) OR r.client_id IN (SELECT p.client_id FROM {$px}project_assignments pa INNER JOIN {$px}projects p ON p.id = pa.project_id WHERE pa.user_id = %d AND pa.is_active = 1))
                 ORDER BY
                    CASE WHEN r.date = %s THEN 0 ELSE 1 END ASC,
                    CASE WHEN r.status IN ('in_progress','active','started') THEN 0 ELSE 1 END ASC,
                    r.date DESC,
                    r.updated_at DESC,
                    r.id DESC
                 LIMIT 1",
                $uid, $uid, $uid, $uid, $uid, $today
            ), ARRAY_A);
            if ($row) {
                $context['route_id'] = (int) ($row['route_id'] ?? 0);
                $context['client_id'] = (int) ($row['client_id'] ?? 0);
                $context['project_id'] = (int) ($row['project_id'] ?? 0);
            }
        }

        if (!$context['project_id'] && !$context['client_id'] && is_user_logged_in()) {
            $scope = Permissions::get_scope(get_current_user_id());
            $project_ids = array_values(array_filter(array_map('absint', (array) ($scope['project_ids'] ?? []))));
            $client_ids = array_values(array_filter(array_map('absint', (array) ($scope['client_ids'] ?? []))));
            if (count($project_ids) === 1) $context['project_id'] = $project_ids[0];
            if (count($client_ids) === 1) $context['client_id'] = $client_ids[0];
        }

        return $context;
    }

    public static function resolve(array $args = []): ?array {
        global $wpdb;
        $context = self::get_context($args);
        $table = self::table();
        $forms = Forms::table();

        $rows = $wpdb->get_results(
            "SELECT b.*, f.title AS form_title, f.status AS form_status
             FROM {$table} b
             INNER JOIN {$forms} f ON f.id = b.form_id
             WHERE b.is_active = 1
             ORDER BY b.priority DESC, b.id DESC",
            ARRAY_A
        ) ?: [];

        $best = null;
        $bestWeight = -1;
        foreach ($rows as $row) {
            if (($row['form_status'] ?? '') !== 'active') continue;
            $weight = self::match_weight($row, $context);
            if ($weight < 0) continue;
            if ($weight > $bestWeight) {
                $best = $row;
                $bestWeight = $weight;
            }
        }
        return $best;
    }

    private static function match_weight(array $binding, array $context): int {
        $rules = [
            'stop_id' => 500,
            'route_id' => 400,
            'project_id' => 300,
            'client_id' => 200,
            'location_id' => 100,
        ];
        $score = 0;
        $matchedSpecific = false;
        foreach ($rules as $field => $points) {
            $expected = (int) ($binding[$field] ?? 0);
            if ($expected > 0) {
                if ((int) ($context[$field] ?? 0) !== $expected) return -1;
                $score += $points;
                $matchedSpecific = true;
            }
        }
        if (!$matchedSpecific) return -1;
        $score += max(0, (int) ($binding['priority'] ?? 0));
        return $score;
    }
}
