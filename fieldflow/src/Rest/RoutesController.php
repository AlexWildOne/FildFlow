<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use RoutesPro\Support\Permissions;

class RoutesController {
    const NS = 'routespro/v1';

    public function register_routes() {
        // /routes
        register_rest_route(self::NS, '/routes', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'list_routes'],
                'permission_callback' => [$this, 'can_list_routes'],
            ],
            [
                'methods'  => 'POST',
                'callback' => [$this, 'create_route'],
                'permission_callback' => function() { return current_user_can('routespro_manage'); }
            ],
        ]);

        // /routes/{id}
        register_rest_route(self::NS, '/routes/(?P<id>\d+)', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'get_route'],
                'permission_callback' => [$this, 'can_read_route'],
            ],
            [
                'methods'  => 'PATCH',
                'callback' => [$this, 'update_route'],
                'permission_callback' => [$this, 'can_edit_route'],
            ],
            [
                'methods'  => 'DELETE',
                'callback' => [$this, 'delete_route'],
                'permission_callback' => function() { return current_user_can('routespro_manage'); }
            ],
        ]);

        // /routes/{id}/stops  (GET para debug + DELETE para limpar antes de recriar)
        register_rest_route(self::NS, '/routes/(?P<id>\d+)/stops', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'list_stops'],
                'permission_callback' => [$this, 'can_read_route'],
            ],
            [
                'methods'  => 'DELETE',
                'callback' => [$this, 'clear_stops'],
                'permission_callback' => function() { return current_user_can('routespro_manage'); }
            ],
        ]);

        // /stops (criar)
        register_rest_route(self::NS, '/stops', [
            [
                'methods'  => 'POST',
                'callback' => [$this, 'create_stop'],
                'permission_callback' => [$this, 'can_create_stop'],
            ]
        ]);

        // /stops/{id} (apagar/atualizar)
        register_rest_route(self::NS, '/stops/(?P<id>\d+)', [
            [
                'methods'  => 'DELETE',
                'callback' => [$this, 'delete_stop'],
                'permission_callback' => [$this, 'can_mutate_stop'],
            ],
            [
                'methods'  => 'PATCH',
                'callback' => [$this, 'update_stop_seq_or_status'],
                'permission_callback' => [$this, 'can_mutate_stop'],
            ]
        ]);
    }

    /* =========================================================
     * PERMISSIONS HELPERS
     * ======================================================= */

    private function is_route_owned_by_current($route_id){
        return Permissions::can_access_route((int)$route_id, get_current_user_id());
    }

    public function can_list_routes(WP_REST_Request $req){
        // Permite GET /routes?user_id=me a utilizadores autenticados
        $mine = ($req->get_param('user_id') === 'me');
        if ($mine) return is_user_logged_in();
        // Gestores veem tudo, users com âmbito restrito podem listar por cliente/campanha
        if (current_user_can('routespro_manage')) return true;
        $client_id = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        return !is_wp_error(Permissions::assert_scope_or_error($client_id, $project_id)) && Permissions::can_access_front();
    }

    public function can_read_route(WP_REST_Request $req){
        $id = absint($req['id']);
        return $this->is_route_owned_by_current($id);
    }

    public function can_edit_route(WP_REST_Request $req){
        // Permite PATCH se o utilizador tiver acesso à rota
        $id = absint($req['id']);
        return $this->is_route_owned_by_current($id);
    }

    public function can_create_stop(WP_REST_Request $req){
        // Verifica route_id no corpo
        $p = $req->get_json_params() ?: [];
        $route_id = absint($p['route_id'] ?? 0);
        return $route_id ? $this->is_route_owned_by_current($route_id) : current_user_can('routespro_manage');
    }

    public function can_mutate_stop(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        $route_id = (int)$wpdb->get_var($wpdb->prepare("SELECT route_id FROM {$px}route_stops WHERE id=%d",$id));
        return $route_id ? $this->is_route_owned_by_current($route_id) : current_user_can('routespro_manage');
    }

    /* =========================================================
     * UTILS
     * ======================================================= */

    /**
     * Converte ISO8601/datetime-local para formato MySQL (UTC).
     * Aceita: "2025-10-29T09:30", "2025-10-29T09:30:00Z", etc.
     */
    private function iso_to_mysql( $val ){
        if (!$val) return null;
        $ts = strtotime($val);
        if (!$ts) return null;
        // Armazena em UTC
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /* =========================================================
     * ROUTES
     * ======================================================= */

    public function list_routes(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $date       = sanitize_text_field($req->get_param('date') ?: current_time('Y-m-d'));
        $mine       = ($req->get_param('user_id') === 'me');
        $client_id  = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);

        $where = ["r.date = %s"]; $args = [$date];

        if ($client_id)  { $where[] = "r.client_id = %d";  $args[] = $client_id; }
        if ($project_id) { $where[] = "r.project_id = %d"; $args[] = $project_id; }

        if ($mine) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_Error('forbidden','Não autenticado',['status'=>401]);

            $mineScope = Permissions::get_scope($uid);
            $routeIds = array_values(array_filter(array_map('absint', (array)($mineScope['route_ids'] ?? []))));
            $projectIds = array_values(array_filter(array_map('absint', (array)($mineScope['project_ids'] ?? []))));
            $clientIds = array_values(array_filter(array_map('absint', (array)($mineScope['client_ids'] ?? []))));
            $parts = ["r.owner_user_id = %d", "EXISTS (SELECT 1 FROM {$px}assignments ax WHERE ax.route_id = r.id AND ax.user_id = %d AND ax.is_active = 1)"];
            $args[] = $uid; $args[] = $uid;
            if ($routeIds) {
                $parts[] = 'r.id IN (' . implode(',', array_fill(0, count($routeIds), '%d')) . ')';
                $args = array_merge($args, $routeIds);
            }
            if ($projectIds) {
                $parts[] = 'r.project_id IN (' . implode(',', array_fill(0, count($projectIds), '%d')) . ')';
                $args = array_merge($args, $projectIds);
            }
            if ($clientIds) {
                $parts[] = 'r.client_id IN (' . implode(',', array_fill(0, count($clientIds), '%d')) . ')';
                $args = array_merge($args, $clientIds);
            }
            $where[] = '(' . implode(' OR ', array_unique($parts)) . ')';

            $sql = "
                SELECT r.*
                FROM {$px}routes r
                WHERE ".implode(' AND ', $where)."
                ORDER BY r.date DESC, r.id DESC
            ";
        } else {
            $scopeCheck = Permissions::assert_scope_or_error($client_id, $project_id);
            if (is_wp_error($scopeCheck)) return $scopeCheck;
            list($scopeSql, $scopeArgs) = Permissions::scope_sql('r');
            if ($scopeSql !== '1=1') { $where[] = $scopeSql; $args = array_merge($args, $scopeArgs); }
            $sql = "
                SELECT r.*
                FROM {$px}routes r
                WHERE ".implode(' AND ', $where)."
                ORDER BY r.id DESC
            ";
        }

        $routes = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        return new WP_REST_Response(['routes' => $routes], 200);
    }

    public function get_route(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);

        $route = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}routes WHERE id=%d", $id), ARRAY_A);
        if (!$route) return new WP_Error('not_found', 'Route not found', ['status'=>404]);
        if (!$this->is_route_owned_by_current($id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        $stops = $wpdb->get_results($wpdb->prepare("
            SELECT rs.*, l.name AS location_name, l.address, l.lat, l.lng
            FROM {$px}route_stops rs
            INNER JOIN {$px}locations l ON l.id = rs.location_id
            WHERE rs.route_id = %d
            ORDER BY rs.seq ASC, rs.id ASC
        ", $id), ARRAY_A);

        // assignments (utilizadores + função)
        $assigns = $wpdb->get_results($wpdb->prepare("
            SELECT a.id, a.user_id, a.role,
                   u.display_name, u.user_email, u.user_login
            FROM {$px}assignments a
            LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
            WHERE a.route_id = %d
            ORDER BY a.id ASC
        ", $id), ARRAY_A);

        // Flatten para o front
        foreach ($stops as &$stop) {
            $stop_meta = !empty($stop['meta_json']) ? json_decode((string) $stop['meta_json'], true) : [];
            if (is_array($stop_meta)) {
                $stop['visit_time_min'] = max(0, (int) ($stop_meta['visit_time_min'] ?? 0));
                $stop['visit_time_mode'] = sanitize_text_field((string) ($stop_meta['visit_time_mode'] ?? ''));
            }
        }
        unset($stop);

        $payload = array_merge($route, [
            'stops'      => $stops,
            'assignments'=> $assigns,
        ]);

        return new WP_REST_Response($payload, 200);
    }

    public function create_route(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $data = $req->get_json_params() ?: [];
        $uid  = get_current_user_id();

        $client_id = absint($data['client_id'] ?? 0);
        if (!$client_id) return new WP_Error('bad_request', 'client_id obrigatório', ['status'=>400]);

        $project_id = absint($data['project_id'] ?? 0) ?: null;

        // owner: se admin passar explicitamente, respeita; senão, current user
        $owner_id = $uid;
        if (isset($data['owner_user_id']) && current_user_can('routespro_manage')) {
            $owner_id = absint($data['owner_user_id']) ?: $uid;
        }

        $row = [
            'client_id'     => $client_id,
            'date'          => sanitize_text_field($data['date'] ?? current_time('Y-m-d')),
            'status'        => sanitize_text_field($data['status'] ?? 'draft'),
            'owner_user_id' => $owner_id,
            'meta_json'     => wp_json_encode($data['meta'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
        ];
        if ($project_id !== null) { $row['project_id'] = $project_id; }

        $ok = $wpdb->insert("{$px}routes", $row);
        if (!$ok) return new WP_Error('db_error', $wpdb->last_error ?: 'DB insert falhou', ['status'=>500]);

        $id = (int)$wpdb->insert_id;
        // assignment default para o owner
        \RoutesPro\Support\AssignmentMatrix::sync_route_owner_assignment((int)$id, (int)$owner_id, 'owner');

        return new WP_REST_Response(['id'=>$id], 201);
    }

    public function update_route(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $id = absint($req['id']);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$px}routes WHERE id=%d", $id));
        if (!$exists) return new WP_Error('not_found','Rota não existe', ['status'=>404]);
        if (!$this->is_route_owned_by_current($id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        $data = $req->get_json_params() ?: [];
        $fields = []; $formats = []; $where = ['id'=>$id];

        if (isset($data['status']))        { $fields['status']        = sanitize_text_field($data['status']);        $formats[]='%s'; }
        if (isset($data['owner_user_id'])) { $fields['owner_user_id'] = absint($data['owner_user_id']);              $formats[]='%d'; }
        if (isset($data['meta']))          { $fields['meta_json']     = wp_json_encode($data['meta'], JSON_UNESCAPED_UNICODE); $formats[]='%s'; }
        if (isset($data['date']))          { $fields['date']          = sanitize_text_field($data['date']);          $formats[]='%s'; }
        if (array_key_exists('client_id', $data))  { $fields['client_id']  = absint($data['client_id']);  $formats[]='%d'; }

        $set_project_null = false;
        if (array_key_exists('project_id', $data)) {
            $pid = absint($data['project_id']) ?: null;
            if ($pid === null) {
                // Forçar NULL de forma explícita
                $set_project_null = true;
            } else {
                $fields['project_id'] = $pid;
                $formats[] = '%d';
            }
        }

        if (!$fields && !$set_project_null) {
            return new WP_REST_Response(['ok'=>true], 200);
        }

        // Update normal
        if ($fields) {
            $ok = $wpdb->update("{$px}routes", $fields, $where, $formats, ['%d']);
            if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB update falhou', ['status'=>500]);
        }

        // Set project_id = NULL caso pedido
        if ($set_project_null) {
            $q = $wpdb->prepare("UPDATE {$px}routes SET project_id = NULL WHERE id = %d", $id);
            $ok2 = $wpdb->query($q);
            if ($ok2 === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB update falhou (project_id NULL)', ['status'=>500]);
        }

        return new WP_REST_Response(['ok'=>true], 200);
    }

    public function delete_route(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        if (!current_user_can('routespro_manage')) return new WP_Error('forbidden','Apenas BO pode apagar rotas', ['status'=>403]);

        // limpeza em cascata
        $stop_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$px}route_stops WHERE route_id=%d", $id));
        if ($stop_ids) {
            $in = implode(',', array_map('intval', $stop_ids));
            $wpdb->query("DELETE FROM {$px}events WHERE route_stop_id IN ($in)");
        }
        $wpdb->delete($px.'assignments',  ['route_id'=>$id], ['%d']);
        $wpdb->delete($px.'route_stops',  ['route_id'=>$id], ['%d']);
        $ok = $wpdb->delete($px.'routes', ['id'=>$id], ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB delete falhou', ['status'=>500]);

        return new WP_REST_Response(['ok'=>true],200);
    }

    /* =========================================================
     * STOPS
     * ======================================================= */

    public function list_stops(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        if (!$this->is_route_owned_by_current($id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        $stops = $wpdb->get_results($wpdb->prepare("
            SELECT rs.*, l.name AS location_name, l.address, l.lat, l.lng
            FROM {$px}route_stops rs
            INNER JOIN {$px}locations l ON l.id = rs.location_id
            WHERE rs.route_id = %d
            ORDER BY rs.seq ASC, rs.id ASC
        ", $id), ARRAY_A);

        return new WP_REST_Response(['stops'=>$stops], 200);
    }

    public function clear_stops(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        if (!current_user_can('routespro_manage')) return new WP_Error('forbidden','Apenas BO pode limpar paragens', ['status'=>403]);

        $route_id = absint($req['id']);
        if (!$route_id) return new WP_Error('bad_request','route_id em falta', ['status'=>400]);

        // apagar eventos relacionados às paragens desta rota
        $stop_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$px}route_stops WHERE route_id=%d", $route_id));
        if ($stop_ids) {
            $in = implode(',', array_map('intval', $stop_ids));
            $wpdb->query("DELETE FROM {$px}events WHERE route_stop_id IN ($in)");
        }

        $ok = $wpdb->delete($px.'route_stops', ['route_id'=>$route_id], ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB delete falhou', ['status'=>500]);

        return new WP_REST_Response(['ok'=>true], 200);
    }

    public function create_stop(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $p = $req->get_json_params() ?: [];

        $route_id = absint($p['route_id'] ?? 0);
        $loc_id   = absint($p['location_id'] ?? 0);
        if (!$route_id || !$loc_id) return new WP_Error('bad_request','route_id/location_id obrigatórios', ['status'=>400]);
        if (!$this->is_route_owned_by_current($route_id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        // seq explícito ou next auto
        if (isset($p['seq']) && $p['seq'] !== null && $p['seq'] !== '') {
            $seq = absint($p['seq']);
        } else {
            $seq = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(seq),-1)+1 FROM {$px}route_stops WHERE route_id=%d", $route_id));
        }

        $visit_time_min = isset($p['visit_time_min']) ? max(0, (int) $p['visit_time_min']) : 0;
        $visit_time_mode = sanitize_text_field((string) ($p['visit_time_mode'] ?? ''));
        $row = [
            'route_id'    => $route_id,
            'location_id' => $loc_id,
            'seq'         => $seq,
            'status'      => sanitize_text_field($p['status'] ?? 'pending'),
            'note'        => sanitize_text_field($p['note'] ?? ''),
            'meta_json'   => wp_json_encode(['visit_time_min' => $visit_time_min, 'visit_time_mode' => $visit_time_mode], JSON_UNESCAPED_UNICODE),
        ];
        $ok = $wpdb->insert($px.'route_stops', $row, ['%d','%d','%d','%s','%s','%s']);
        if (!$ok) return new WP_Error('db_error', $wpdb->last_error ?: 'DB insert falhou', ['status'=>500]);

        return new WP_REST_Response(['id'=>$wpdb->insert_id],201);
    }

    public function delete_stop(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        $route_id = (int)$wpdb->get_var($wpdb->prepare("SELECT route_id FROM {$px}route_stops WHERE id=%d",$id));
        if (!$route_id) return new WP_Error('not_found','Stop não existe',['status'=>404]);
        if (!$this->is_route_owned_by_current($route_id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        // apagar eventos relacionados (se existir tabela)
        $wpdb->delete($px.'events', ['route_stop_id'=>$id], ['%d']);
        $ok = $wpdb->delete($px.'route_stops', ['id'=>$id], ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB delete falhou', ['status'=>500]);

        return new WP_REST_Response(['ok'=>true],200);
    }

    public function update_stop_seq_or_status(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        $p  = $req->get_json_params() ?: [];

        $route_id = (int)$wpdb->get_var($wpdb->prepare("SELECT route_id FROM {$px}route_stops WHERE id=%d",$id));
        if (!$route_id) return new WP_Error('not_found','Stop não existe',['status'=>404]);
        if (!$this->is_route_owned_by_current($route_id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        // normalização de datas
        $arrived_at  = isset($p['arrived_at'])  ? $this->iso_to_mysql($p['arrived_at'])   : null;
        $departed_at = isset($p['departed_at']) ? $this->iso_to_mysql($p['departed_at'])  : null;

        $fields = []; $formats = [];

        // existentes
        if (isset($p['seq']))           { $fields['seq']           = absint($p['seq']);                       $formats[]='%d'; }
        if (isset($p['status']))        { $fields['status']        = sanitize_text_field($p['status']);       $formats[]='%s'; }
        if (isset($p['note']))          { $fields['note']          = sanitize_text_field($p['note']);         $formats[]='%s'; }

        // novos campos de reporte
        if (isset($p['fail_reason']))   { $fields['fail_reason']   = sanitize_text_field($p['fail_reason']);  $formats[]='%s'; }
        if (isset($p['photo_url']))     { $fields['photo_url']     = esc_url_raw($p['photo_url']);            $formats[]='%s'; }
        if (isset($p['signature_data'])){ $fields['signature_data']= $p['signature_data'];                    $formats[]='%s'; } // dataURL base64

        if ($arrived_at !== null)       { $fields['arrived_at']    = $arrived_at;                             $formats[]='%s'; }
        if ($departed_at !== null)      { $fields['departed_at']   = $departed_at;                            $formats[]='%s'; }
        if (isset($p['duration_s']))    { $fields['duration_s']    = absint($p['duration_s']);                $formats[]='%d'; }

        if (isset($p['qty']))           { $fields['qty']           = floatval($p['qty']);                     $formats[]='%f'; }
        if (isset($p['weight']))        { $fields['weight']        = floatval($p['weight']);                  $formats[]='%f'; }
        if (isset($p['volume']))        { $fields['volume']        = floatval($p['volume']);                  $formats[]='%f'; }

        if (isset($p['real_lat']))      { $fields['real_lat']      = floatval($p['real_lat']);                $formats[]='%f'; }
        if (isset($p['real_lng']))      { $fields['real_lng']      = floatval($p['real_lng']);                $formats[]='%f'; }

        if (!$fields) return new WP_REST_Response(['ok'=>true],200);

        $ok = $wpdb->update($px.'route_stops', $fields, ['id'=>$id], $formats, ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB update falhou', ['status'=>500]);

        return new WP_REST_Response(['ok'=>true],200);
    }
}
