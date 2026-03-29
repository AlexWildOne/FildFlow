<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) { exit; }

class EventsController {
    const NS = 'routespro/v1';

    public function register_routes() {
        // Criar evento (nota, checkin, checkout, foto, falha, etc.)
        register_rest_route(self::NS, '/events', [
            'methods'  => 'POST',
            'callback' => [$this, 'create'],
            'permission_callback' => function(WP_REST_Request $req){
                // Permite a utilizadores autenticados mas valida no corpo o stop/rota
                return is_user_logged_in();
            }
        ]);

        // Atualizar stop (status, nota, seq) – legado/simples
        register_rest_route(self::NS, '/stops/(?P<id>\d+)', [
            'methods'  => 'PATCH',
            'callback' => [$this, 'update_stop'],
            'permission_callback' => function(WP_REST_Request $req){
                return is_user_logged_in();
            }
        ]);

        register_rest_route(self::NS, '/form-submissions', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_form_submissions'],
            'permission_callback' => function(WP_REST_Request $req){
                return is_user_logged_in();
            }
        ]);
    }

    /* =========================================================
     * HELPERS
     * ======================================================= */

    private function px(){ global $wpdb; return $wpdb->prefix.'routespro_'; }

    private function iso_to_mysql($val){
        if (!$val) return null;
        $ts = strtotime($val);
        if (!$ts) return null;
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function get_stop_route_id($stop_id){
        global $wpdb; $px = $this->px();
        $rid = (int)$wpdb->get_var($wpdb->prepare("SELECT route_id FROM {$px}route_stops WHERE id=%d", $stop_id));
        return $rid ?: 0;
    }

    private function user_can_for_route($route_id){
        return \RoutesPro\Support\Permissions::can_access_route((int)$route_id, get_current_user_id());
    }

    private function sanitize_float_or_null($v){
        if ($v === '' || $v === null) return null;
        $v = is_numeric($v) ? (float)$v : null;
        return $v;
    }

    /* =========================================================
     * POST /events
     * Aceita:
     *  - JSON:
     *      { route_stop_id, event_type, payload{ note, fail_reason, arrived_at, departed_at, qty, weight, volume, real_lat, real_lng, photo_url, signature_data } }
     *  - multipart/form-data:
     *      route_stop_id, event_type, file (opcional), payload (JSON opcional)
     *  - event_type recomendados: note | checkin | checkout | failure | photo | signature | update_stop
     * Efeitos:
     *  - Escreve em routespro_events (route_stop_id, user_id, event_type, payload_json)
     *  - Opcionalmente atualiza routespro_route_stops (status, tempos, contadores, geostamp, foto, assinatura, motivo falha)
     * ======================================================= */

    public function create(WP_REST_Request $req) {
        global $wpdb; $px = $this->px();

        $ctype = (string)($req->get_header('content-type') ?? '');
        $is_multipart = stripos($ctype, 'multipart/form-data') !== false;

        // Extrair parâmetros base
        $route_stop_id = $is_multipart
            ? absint($req->get_param('route_stop_id'))
            : absint(($req->get_json_params()['route_stop_id'] ?? 0));

        if (!$route_stop_id) return new WP_Error('bad_request','route_stop_id em falta', ['status'=>400]);

        $route_id = $this->get_stop_route_id($route_stop_id);
        if (!$route_id) return new WP_Error('not_found','Paragem inexistente', ['status'=>404]);
        if (!$this->user_can_for_route($route_id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        $event_type = $is_multipart
            ? sanitize_text_field($req->get_param('event_type'))
            : sanitize_text_field(($req->get_json_params()['event_type'] ?? 'note'));
        if ($event_type === '') $event_type = 'note';

        // Payload base
        $payload = [];
        if ($is_multipart) {
            $raw = (string)$req->get_param('payload');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $payload = $decoded;
            }
        } else {
            $payload = $req->get_json_params()['payload'] ?? [];
            if (!is_array($payload)) $payload = [];
        }

        // Upload (foto/prova) se multipart
        $attachment_id = null;
        if ($is_multipart && !empty($_FILES['file']['tmp_name'])) {
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/image.php';

            $attach_id = media_handle_upload('file', 0);
            if (is_wp_error($attach_id)) {
                return new WP_Error('upload_error', $attach_id->get_error_message(), ['status'=>400]);
            }
            $attachment_id = (int)$attach_id;
            $payload['attachment_id']  = $attachment_id;
            $payload['attachment_url'] = wp_get_attachment_url($attachment_id);
            // Também refletir como photo_url se fizer sentido
            if (empty($payload['photo_url'])) $payload['photo_url'] = $payload['attachment_url'];
        }

        // Normalizações rápidas (datas/numéricos)
        $arrived_at  = isset($payload['arrived_at'])  ? $this->iso_to_mysql($payload['arrived_at'])  : null;
        $departed_at = isset($payload['departed_at']) ? $this->iso_to_mysql($payload['departed_at']) : null;

        $qty    = array_key_exists('qty',    $payload) ? $this->sanitize_float_or_null($payload['qty'])    : null;
        $weight = array_key_exists('weight', $payload) ? $this->sanitize_float_or_null($payload['weight']) : null;
        $volume = array_key_exists('volume', $payload) ? $this->sanitize_float_or_null($payload['volume']) : null;

        $real_lat = array_key_exists('real_lat', $payload) ? $this->sanitize_float_or_null($payload['real_lat']) : null;
        $real_lng = array_key_exists('real_lng', $payload) ? $this->sanitize_float_or_null($payload['real_lng']) : null;

        $note         = isset($payload['note']) ? sanitize_text_field($payload['note']) : null;
        $fail_reason  = isset($payload['fail_reason']) ? sanitize_text_field($payload['fail_reason']) : null;
        $photo_url    = isset($payload['photo_url']) ? esc_url_raw($payload['photo_url']) : null;
        $signature_b64= isset($payload['signature_data']) ? (string)$payload['signature_data'] : null;

        // Efeitos no STOP conforme o tipo de evento
        $stop_updates = [];
        $stop_formats = [];

        switch ($event_type) {
            case 'checkin':
                // Se não vier arrived_at, usar agora
                if (!$arrived_at) $arrived_at = gmdate('Y-m-d H:i:s');
                $stop_updates['arrived_at'] = $arrived_at; $stop_formats[] = '%s';
                // Opcionalmente marcar como "in_progress"
                $stop_updates['status'] = 'in_progress';   $stop_formats[] = '%s';
                break;

            case 'checkout':
                // Se não vier departed_at, usar agora
                if (!$departed_at) $departed_at = gmdate('Y-m-d H:i:s');
                $stop_updates['departed_at'] = $departed_at; $stop_formats[] = '%s';
                // Duração se houver arrived_at conhecido
                if (!$arrived_at) {
                    // tenta ler o arrived_at atual
                    global $wpdb;
                    $current_arr = $wpdb->get_var($wpdb->prepare("SELECT arrived_at FROM {$px}route_stops WHERE id=%d", $route_stop_id));
                    if ($current_arr) $arrived_at = $current_arr;
                }
                if ($arrived_at && $departed_at) {
                    $dur = max(0, strtotime($departed_at) - strtotime($arrived_at));
                    $stop_updates['duration_s'] = (int)$dur; $stop_formats[] = '%d';
                }
                // Concluir
                $stop_updates['status'] = 'done'; $stop_formats[] = '%s';
                break;

            case 'failure':
                if ($fail_reason !== null) { $stop_updates['fail_reason'] = $fail_reason; $stop_formats[] = '%s'; }
                $stop_updates['status'] = 'failed'; $stop_formats[] = '%s';
                // Se veio departed_at/arrived_at, guardar também
                if ($arrived_at !== null){ $stop_updates['arrived_at'] = $arrived_at; $stop_formats[] = '%s'; }
                if ($departed_at !== null){ $stop_updates['departed_at'] = $departed_at; $stop_formats[] = '%s'; }
                break;

            case 'photo':
                if ($photo_url !== null) { $stop_updates['photo_url'] = $photo_url; $stop_formats[] = '%s'; }
                break;

            case 'signature':
                if ($signature_b64 !== null) { $stop_updates['signature_data'] = $signature_b64; $stop_formats[] = '%s'; }
                break;

            case 'note':
            case 'update_stop':
            default:
                // Sem efeito especial; apenas propagar campos se vierem
                break;
        }

        // Campos comuns enviados no payload (independente do tipo)
        if ($note !== null)        { $stop_updates['note']   = $note;        $stop_formats[] = '%s'; }
        if ($arrived_at !== null)  { $stop_updates['arrived_at']  = $arrived_at;  $stop_formats[] = '%s'; }
        if ($departed_at !== null) { $stop_updates['departed_at'] = $departed_at; $stop_formats[] = '%s'; }
        if ($qty !== null)         { $stop_updates['qty']    = $qty;          $stop_formats[] = '%f'; }
        if ($weight !== null)      { $stop_updates['weight'] = $weight;       $stop_formats[] = '%f'; }
        if ($volume !== null)      { $stop_updates['volume'] = $volume;       $stop_formats[] = '%f'; }
        if ($real_lat !== null)    { $stop_updates['real_lat'] = $real_lat;   $stop_formats[] = '%f'; }
        if ($real_lng !== null)    { $stop_updates['real_lng'] = $real_lng;   $stop_formats[] = '%f'; }
        if ($photo_url !== null)   { $stop_updates['photo_url'] = $photo_url; $stop_formats[] = '%s'; }
        if ($signature_b64 !== null){ $stop_updates['signature_data'] = $signature_b64; $stop_formats[] = '%s'; }

        // Persistir updates no STOP (se houver)
        if (!empty($stop_updates)) {
            $ok = $wpdb->update($px.'route_stops', $stop_updates, ['id'=>$route_stop_id], $stop_formats, ['%d']);
            if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB update falhou', ['status'=>500]);
        }

        // Gravar evento
        $wpdb->insert($px.'events', [
            'route_stop_id' => $route_stop_id,
            'user_id'       => get_current_user_id(),
            'event_type'    => $event_type,
            'payload_json'  => wp_json_encode($payload ?: new \stdClass()),
            // opcional: created_at via DEFAULT CURRENT_TIMESTAMP na tabela
        ], ['%d','%d','%s','%s']);

        return new WP_REST_Response([
            'id'             => (int)$wpdb->insert_id,
            'ok'             => true,
            'attachment_id'  => $attachment_id,
            'route_stop_id'  => $route_stop_id,
            'applied_updates'=> array_keys($stop_updates),
        ], 201);
    }

    /* =========================================================
     * PATCH /stops/{id}  (legado/simples)
     * Atualiza seq/status/note e regista um evento "update_stop"
     * ======================================================= */

    public function update_stop(WP_REST_Request $req) {
        global $wpdb; $px = $this->px();
        $id = absint($req['id']);
        if (!$id) return new WP_Error('bad_request','id inválido', ['status'=>400]);

        $route_id = $this->get_stop_route_id($id);
        if (!$route_id) return new WP_Error('not_found','Paragem inexistente', ['status'=>404]);
        if (!$this->user_can_for_route($route_id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        $p = $req->get_json_params() ?: [];

        $fields = []; $formats = [];
        if (isset($p['status'])) { $fields['status'] = sanitize_text_field($p['status']); $formats[] = '%s'; }
        if (isset($p['note']))   { $fields['note']   = sanitize_text_field($p['note']);   $formats[] = '%s'; }
        if (isset($p['seq']))    { $fields['seq']    = absint($p['seq']);                  $formats[] = '%d'; }

        if (!$fields) {
            // mesmo sem updates, regista evento
            $wpdb->insert($px.'events', [
                'route_stop_id' => $id,
                'user_id'       => get_current_user_id(),
                'event_type'    => 'update_stop',
                'payload_json'  => wp_json_encode($p ?: new \stdClass())
            ], ['%d','%d','%s','%s']);
            return new WP_REST_Response(['ok'=>true,'updated'=>0], 200);
        }

        $ok = $wpdb->update($px.'route_stops', $fields, ['id'=>$id], $formats, ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB update falhou', ['status'=>500]);

        $wpdb->insert($px.'events', [
            'route_stop_id' => $id,
            'user_id'       => get_current_user_id(),
            'event_type'    => 'update_stop',
            'payload_json'  => wp_json_encode($p ?: new \stdClass())
        ], ['%d','%d','%s','%s']);

        return new WP_REST_Response(['ok'=>true,'updated'=>1], 200);
    }

    public function list_form_submissions(WP_REST_Request $req) {
        if (!(current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front())) {
            return new WP_Error('forbidden', 'Sem permissões.', ['status' => 403]);
        }
        $filters = [
            'client_id' => absint($req->get_param('client_id')),
            'project_id' => absint($req->get_param('project_id')),
            'owner_user_id' => absint($req->get_param('owner_user_id')),
            'route_id' => absint($req->get_param('route_id')),
            'date_from' => sanitize_text_field((string) $req->get_param('date_from')),
            'date_to' => sanitize_text_field((string) $req->get_param('date_to')),
            'limit' => min(1000, max(1, absint($req->get_param('limit')) ?: 300)),
        ];
        $dataset = \RoutesPro\Admin\FormSubmissions::get_submission_dataset($filters);
        return new WP_REST_Response($dataset, 200);
    }

}
