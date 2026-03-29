<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use RoutesPro\Support\Permissions;

class StatsController {
    const NS = 'routespro/v1';

    public function register_routes() {
        register_rest_route(self::NS, '/stats', [
            'methods'  => 'GET',
            'callback' => [$this, 'stats'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || Permissions::can_access_front(); }
        ]);
        register_rest_route(self::NS, '/heatmap', [
            'methods'  => 'GET',
            'callback' => [$this, 'heatmap'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || Permissions::can_access_front(); }
        ]);
    }

    private function build_where(WP_REST_Request $req, array &$args, string $routeAlias = 'r'): string {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $from = sanitize_text_field($req->get_param('from')) ?: date('Y-m-d', strtotime('-7 days'));
        $to   = sanitize_text_field($req->get_param('to'))   ?: date('Y-m-d');
        $client_id  = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        $user_id    = absint($req->get_param('user_id') ?: 0);
        $role       = sanitize_text_field($req->get_param('role') ?: '');

        $scopeCheck = Permissions::assert_scope_or_error($client_id, $project_id);
        if (is_wp_error($scopeCheck)) return '';

        $where = ["{$routeAlias}.date BETWEEN %s AND %s"];
        $args  = [$from, $to];
        if ($client_id)  { $where[] = "{$routeAlias}.client_id = %d";  $args[] = $client_id; }
        if ($project_id) { $where[] = "{$routeAlias}.project_id = %d"; $args[] = $project_id; }
        list($scopeSql, $scopeArgs) = Permissions::scope_sql($routeAlias);
        if ($scopeSql !== '1=1') { $where[] = $scopeSql; $args = array_merge($args, $scopeArgs); }

        if ($user_id && $role) {
            $where[] = "({$routeAlias}.owner_user_id = %d OR EXISTS (SELECT 1 FROM {$px}assignments ax WHERE ax.route_id = {$routeAlias}.id AND ax.user_id = %d AND ax.role = %s))";
            $args[] = $user_id; $args[] = $user_id; $args[] = $role;
        } elseif ($user_id) {
            $where[] = "({$routeAlias}.owner_user_id = %d OR EXISTS (SELECT 1 FROM {$px}assignments ax WHERE ax.route_id = {$routeAlias}.id AND ax.user_id = %d))";
            $args[] = $user_id; $args[] = $user_id;
        } elseif ($role) {
            $where[] = "EXISTS (SELECT 1 FROM {$px}assignments ax WHERE ax.route_id = {$routeAlias}.id AND ax.role = %s)";
            $args[] = $role;
        }
        return $wpdb->prepare(implode(' AND ', $where), ...$args);
    }

    public function stats(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $args = [];
        $sqlWhere = $this->build_where($req, $args, 'r');
        if ($sqlWhere === '') return new \WP_Error('forbidden', 'Sem acesso ao âmbito pedido.', ['status' => 403]);

        $from = sanitize_text_field($req->get_param('from')) ?: date('Y-m-d', strtotime('-7 days'));
        $to   = sanitize_text_field($req->get_param('to'))   ?: date('Y-m-d');

        $total_routes = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT r.id FROM {$px}routes r WHERE $sqlWhere GROUP BY r.id) x");
        $total_stops = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT s.id FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id WHERE $sqlWhere) x");
        $done_stops = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT s.id FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id WHERE $sqlWhere AND s.status IN ('done','completed')) x");
        $completed_routes = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT r.id FROM {$px}routes r WHERE $sqlWhere AND NOT EXISTS (SELECT 1 FROM {$px}route_stops s WHERE s.route_id = r.id AND s.status NOT IN ('done','completed')) GROUP BY r.id) x");
        $on_time = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT e.id FROM {$px}events e INNER JOIN {$px}route_stops s ON s.id = e.route_stop_id INNER JOIN {$px}routes r ON r.id = s.route_id INNER JOIN {$px}locations l ON l.id = s.location_id WHERE $sqlWhere AND e.event_type = 'checkin' AND l.window_start IS NOT NULL AND l.window_end IS NOT NULL AND TIME(e.created_at) BETWEEN l.window_start AND l.window_end) x");
        $with_windows = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT s.id FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id INNER JOIN {$px}locations l ON l.id = s.location_id WHERE $sqlWhere AND l.window_start IS NOT NULL AND l.window_end IS NOT NULL) x");
        $avg_stop_secs = (int)$wpdb->get_var("SELECT AVG(t.diff_s) FROM (SELECT DISTINCT s.id, TIMESTAMPDIFF(SECOND, ci.created_at, co.created_at) AS diff_s FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id LEFT JOIN {$px}events ci ON ci.route_stop_id = s.id AND ci.event_type = 'checkin' LEFT JOIN {$px}events co ON co.route_stop_id = s.id AND co.event_type = 'checkout' WHERE $sqlWhere AND ci.id IS NOT NULL AND co.id IS NOT NULL) t");

        $res = [
            'from' => $from,
            'to'   => $to,
            'total_routes'        => $total_routes,
            'completed_routes'    => $completed_routes,
            'completion_rate'     => $total_routes ? round($completed_routes/$total_routes*100,1) : 0,
            'total_stops'         => $total_stops,
            'done_stops'          => $done_stops,
            'done_rate'           => $total_stops ? round($done_stops/$total_stops*100,1) : 0,
            'avg_stops_per_route' => $total_routes ? round($total_stops/$total_routes,2) : 0,
            'on_time_rate'        => $with_windows ? round($on_time/$with_windows*100,1) : null,
            'avg_stop_minutes'    => $avg_stop_secs ? round($avg_stop_secs/60,1) : null,
        ];

        $rows = $wpdb->get_results("SELECT r.id as route_id, r.date, r.status as route_status, p.name as project_name, c.name as client_name, COALESCE((SELECT COALESCE(NULLIF(uo.display_name,''), uo.user_login) FROM {$wpdb->users} uo WHERE uo.ID = r.owner_user_id LIMIT 1),(SELECT COALESCE(NULLIF(ua.display_name,''), ua.user_login) FROM {$px}assignments a2 JOIN {$wpdb->users} ua ON ua.ID = a2.user_id WHERE a2.route_id = r.id ORDER BY a2.id ASC LIMIT 1)) AS user_name, COUNT(s.id) as stops, SUM(CASE WHEN s.status IN ('done','completed') THEN 1 ELSE 0 END) as stops_done, ROUND(100.0 * SUM(CASE WHEN s.status IN ('done','completed') THEN 1 ELSE 0 END)/NULLIF(COUNT(s.id),0),1) as done_rate FROM {$px}routes r LEFT JOIN {$px}route_stops s ON s.route_id = r.id LEFT JOIN {$px}projects p ON p.id = r.project_id LEFT JOIN {$px}clients c ON c.id = r.client_id LEFT JOIN {$px}assignments a ON a.route_id = r.id WHERE $sqlWhere GROUP BY r.id ORDER BY r.date DESC, r.id DESC LIMIT 500", ARRAY_A);
        $res['by_day'] = $rows ?: [];
        return new WP_REST_Response($res, 200);
    }

    public function heatmap(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $args = [];
        $sqlWhere = $this->build_where($req, $args, 'r');
        if ($sqlWhere === '') return new \WP_Error('forbidden', 'Sem acesso ao âmbito pedido.', ['status' => 403]);
        $rows = $wpdb->get_results("SELECT t.lat, t.lng, COUNT(*) as c FROM (SELECT DISTINCT s.id, l.lat, l.lng FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id INNER JOIN {$px}locations l ON l.id = s.location_id WHERE $sqlWhere AND l.lat IS NOT NULL AND l.lng IS NOT NULL) t GROUP BY t.lat, t.lng LIMIT 5000", ARRAY_A);
        return new WP_REST_Response(['points'=>$rows ?: []], 200);
    }
}
