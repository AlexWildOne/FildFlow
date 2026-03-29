<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use RoutesPro\Services\MapsFactory;
use RoutesPro\Services\AIFactory;
use RoutesPro\Services\IntegrationPlatform;

if (!defined('ABSPATH')) { exit; }

class IntegrationsController {
    const NS = 'routespro/v1';

    public function register_routes() {
        register_rest_route(self::NS, '/geocode', [
            'methods'  => 'GET',
            'callback' => [$this, 'geocode'],
            'permission_callback' => function(){ return current_user_can('routespro_manage'); }
        ]);

        register_rest_route(self::NS, '/matrix', [
            'methods'  => 'POST',
            'callback' => [$this, 'matrix'],
            'permission_callback' => function(){ return current_user_can('routespro_manage'); }
        ]);

        register_rest_route(self::NS, '/ai/suggest', [
            'methods'  => 'POST',
            'callback' => [$this, 'ai_suggest'],
            'permission_callback' => function(){ return is_user_logged_in(); }
        ]);

        register_rest_route(self::NS, '/integrations/schema', [
            'methods'  => 'GET',
            'callback' => [$this, 'schema'],
            'permission_callback' => [$this, 'can_access_integration_api']
        ]);

        register_rest_route(self::NS, '/integrations/export', [
            'methods'  => 'GET',
            'callback' => [$this, 'export'],
            'permission_callback' => [$this, 'can_access_integration_api']
        ]);

        register_rest_route(self::NS, '/integrations/test', [
            'methods'  => 'POST',
            'callback' => [$this, 'test_connector'],
            'permission_callback' => function(){ return current_user_can('routespro_manage'); }
        ]);

        register_rest_route(self::NS, '/integrations/push', [
            'methods'  => 'POST',
            'callback' => [$this, 'push_connector'],
            'permission_callback' => function(){ return current_user_can('routespro_manage'); }
        ]);
    }

    public function can_access_integration_api(WP_REST_Request $req) {
        if (current_user_can('routespro_manage')) {
            return true;
        }
        $opts = IntegrationPlatform::options();
        if (empty($opts['api_enabled'])) {
            return false;
        }
        $token = (string) $req->get_header('x-routespro-token');
        if ($token === '') {
            $auth = (string) $req->get_header('authorization');
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }
        if ($token === '') {
            $token = sanitize_text_field((string)$req->get_param('token'));
        }
        return hash_equals((string)$opts['api_token'], (string)$token);
    }

    public function schema(WP_REST_Request $req) {
        $resources = [
            'clients' => ['id','name','taxid','email','phone','meta_json','created_at','updated_at'],
            'projects' => ['id','client_id','name','status','meta_json','created_at','updated_at'],
            'locations' => ['id','client_id','project_id','location_type','name','address','district','county','city','parish','postal_code','country','category_id','subcategory_id','contact_person','phone','email','website','lat','lng','external_ref','place_id','source','is_active','is_validated','created_at','updated_at'],
            'routes' => ['id','client_id','project_id','date','status','owner_user_id','meta_json','created_at','updated_at'],
            'route_stops' => ['id','route_id','location_id','seq','planned_arrival','planned_departure','arrived_at','departed_at','duration_s','status','note','fail_reason','photo_url','qty','weight','volume','real_lat','real_lng','meta_json'],
            'events' => ['id','route_stop_id','user_id','event_type','payload_json','created_at'],
        ];
        return new WP_REST_Response([
            'ok' => true,
            'resources' => $resources,
            'params' => ['resource','client_id','project_id','date_from','date_to','page','limit'],
        ], 200);
    }

    public function export(WP_REST_Request $req) {
        global $wpdb;
        $opts = IntegrationPlatform::options();
        $resource = sanitize_key((string)$req->get_param('resource'));
        $allowed = [
            'clients' => !empty($opts['api_allow_clients']),
            'projects' => !empty($opts['api_allow_projects']),
            'locations' => !empty($opts['api_allow_locations']),
            'routes' => !empty($opts['api_allow_routes']),
            'route_stops' => !empty($opts['api_allow_route_stops']),
            'events' => !empty($opts['api_allow_events']),
        ];
        if (empty($allowed[$resource])) {
            return new WP_Error('resource_forbidden', 'Recurso indisponível.', ['status' => 403]);
        }

        $page = max(1, absint($req->get_param('page') ?: 1));
        $limit = max(1, min(5000, absint($req->get_param('limit') ?: (int)$opts['batch_size'])));
        $offset = ($page - 1) * $limit;
        $client_id = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        $date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$req->get_param('date_from')) ? (string)$req->get_param('date_from') : '';
        $date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$req->get_param('date_to')) ? (string)$req->get_param('date_to') : '';

        $px = $wpdb->prefix . 'routespro_';
        $payload = [];
        $sql = '';
        $countSql = '';
        $args = [];

        switch ($resource) {
            case 'clients':
                $sql = "SELECT id,name,taxid,email,phone,meta_json,created_at,updated_at FROM {$px}clients WHERE 1=1";
                $countSql = "SELECT COUNT(*) FROM {$px}clients WHERE 1=1";
                break;
            case 'projects':
                $sql = "SELECT id,client_id,name,status,meta_json,created_at,updated_at FROM {$px}projects WHERE 1=1";
                $countSql = "SELECT COUNT(*) FROM {$px}projects WHERE 1=1";
                if ($client_id) {
                    $sql .= " AND client_id=%d";
                    $countSql .= " AND client_id=%d";
                    $args[] = $client_id;
                }
                break;
            case 'locations':
                $sql = "SELECT id,client_id,project_id,location_type,name,address,district,county,city,parish,postal_code,country,category_id,subcategory_id,contact_person,phone,email,website,lat,lng,external_ref,place_id,source,is_active,is_validated,created_at,updated_at FROM {$px}locations WHERE 1=1";
                $countSql = "SELECT COUNT(*) FROM {$px}locations WHERE 1=1";
                if ($client_id) {
                    $sql .= " AND client_id=%d";
                    $countSql .= " AND client_id=%d";
                    $args[] = $client_id;
                }
                if ($project_id) {
                    $sql .= " AND project_id=%d";
                    $countSql .= " AND project_id=%d";
                    $args[] = $project_id;
                }
                break;
            case 'routes':
                $sql = "SELECT id,client_id,project_id,date,status,owner_user_id,meta_json,created_at,updated_at FROM {$px}routes WHERE 1=1";
                $countSql = "SELECT COUNT(*) FROM {$px}routes WHERE 1=1";
                if ($client_id) {
                    $sql .= " AND client_id=%d";
                    $countSql .= " AND client_id=%d";
                    $args[] = $client_id;
                }
                if ($project_id) {
                    $sql .= " AND project_id=%d";
                    $countSql .= " AND project_id=%d";
                    $args[] = $project_id;
                }
                if ($date_from) {
                    $sql .= " AND date >= %s";
                    $countSql .= " AND date >= %s";
                    $args[] = $date_from;
                }
                if ($date_to) {
                    $sql .= " AND date <= %s";
                    $countSql .= " AND date <= %s";
                    $args[] = $date_to;
                }
                break;
            case 'route_stops':
                $sql = "SELECT rs.id,rs.route_id,rs.location_id,rs.seq,rs.planned_arrival,rs.planned_departure,rs.arrived_at,rs.departed_at,rs.duration_s,rs.status,rs.note,rs.fail_reason,rs.photo_url,rs.qty,rs.weight,rs.volume,rs.real_lat,rs.real_lng,rs.meta_json,r.client_id,r.project_id,r.date,l.name AS location_name FROM {$px}route_stops rs LEFT JOIN {$px}routes r ON r.id=rs.route_id LEFT JOIN {$px}locations l ON l.id=rs.location_id WHERE 1=1";
                $countSql = "SELECT COUNT(*) FROM {$px}route_stops rs LEFT JOIN {$px}routes r ON r.id=rs.route_id WHERE 1=1";
                if ($client_id) {
                    $sql .= " AND r.client_id=%d";
                    $countSql .= " AND r.client_id=%d";
                    $args[] = $client_id;
                }
                if ($project_id) {
                    $sql .= " AND r.project_id=%d";
                    $countSql .= " AND r.project_id=%d";
                    $args[] = $project_id;
                }
                if ($date_from) {
                    $sql .= " AND r.date >= %s";
                    $countSql .= " AND r.date >= %s";
                    $args[] = $date_from;
                }
                if ($date_to) {
                    $sql .= " AND r.date <= %s";
                    $countSql .= " AND r.date <= %s";
                    $args[] = $date_to;
                }
                break;
            case 'events':
                $sql = "SELECT e.id,e.route_stop_id,e.user_id,e.event_type,e.payload_json,e.created_at,rs.route_id,r.client_id,r.project_id,r.date FROM {$px}events e LEFT JOIN {$px}route_stops rs ON rs.id=e.route_stop_id LEFT JOIN {$px}routes r ON r.id=rs.route_id WHERE 1=1";
                $countSql = "SELECT COUNT(*) FROM {$px}events e LEFT JOIN {$px}route_stops rs ON rs.id=e.route_stop_id LEFT JOIN {$px}routes r ON r.id=rs.route_id WHERE 1=1";
                if ($client_id) {
                    $sql .= " AND r.client_id=%d";
                    $countSql .= " AND r.client_id=%d";
                    $args[] = $client_id;
                }
                if ($project_id) {
                    $sql .= " AND r.project_id=%d";
                    $countSql .= " AND r.project_id=%d";
                    $args[] = $project_id;
                }
                if ($date_from) {
                    $sql .= " AND DATE(e.created_at) >= %s";
                    $countSql .= " AND DATE(e.created_at) >= %s";
                    $args[] = $date_from;
                }
                if ($date_to) {
                    $sql .= " AND DATE(e.created_at) <= %s";
                    $countSql .= " AND DATE(e.created_at) <= %s";
                    $args[] = $date_to;
                }
                break;
            default:
                return new WP_Error('bad_request', 'Recurso inválido.', ['status' => 400]);
        }

        $orderBy = in_array($resource, ['routes','route_stops','events'], true) ? ' ORDER BY id DESC' : ' ORDER BY id ASC';
        $rowsArgs = array_merge($args, [$limit, $offset]);
        $rowsSql = $sql . $orderBy . ' LIMIT %d OFFSET %d';
        $rows = $wpdb->get_results($wpdb->prepare($rowsSql, ...$rowsArgs), ARRAY_A) ?: [];
        $total = $args ? (int)$wpdb->get_var($wpdb->prepare($countSql, ...$args)) : (int)$wpdb->get_var($countSql);

        foreach ($rows as &$row) {
            foreach (['meta_json','payload_json'] as $jsonField) {
                if (isset($row[$jsonField]) && is_string($row[$jsonField]) && $row[$jsonField] !== '') {
                    $decoded = json_decode($row[$jsonField], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row[$jsonField] = $decoded;
                    }
                }
            }
        }

        $result = [
            'ok' => true,
            'resource' => $resource,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int)ceil($total / max(1, $limit)),
            'filters' => [
                'client_id' => $client_id,
                'project_id' => $project_id,
                'date_from' => $date_from,
                'date_to' => $date_to,
            ],
            'items' => $rows,
            'exported_at' => current_time('mysql'),
        ];
        IntegrationPlatform::log('api', 'export', 'success', ['resource' => $resource, 'total' => $total, 'page' => $page]);
        return new WP_REST_Response($result, 200);
    }

    public function test_connector(WP_REST_Request $req) {
        $connector = sanitize_key((string)$req->get_param('connector'));
        $config = $this->connector_config($connector);
        if (is_wp_error($config)) {
            return $config;
        }
        $response = wp_remote_request($config['url'], [
            'method' => 'GET',
            'timeout' => $config['timeout'],
            'headers' => $config['headers'],
        ]);
        if (is_wp_error($response)) {
            IntegrationPlatform::log($connector, 'test', 'error', [], $response->get_error_message());
            return new WP_Error('connector_failed', 'Ligação falhou: ' . $response->get_error_message(), ['status' => 502]);
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $ok = $code >= 200 && $code < 400;
        IntegrationPlatform::log($connector, 'test', $ok ? 'success' : 'error', ['http_code' => $code]);
        return new WP_REST_Response([
            'ok' => $ok,
            'connector' => $connector,
            'http_code' => $code,
            'body_sample' => substr((string)wp_remote_retrieve_body($response), 0, 500),
        ], $ok ? 200 : 502);
    }

    public function push_connector(WP_REST_Request $req) {
        $connector = sanitize_key((string)$req->get_param('connector'));
        $resource = sanitize_key((string)$req->get_param('resource'));
        if ($resource === '') {
            $resource = 'route_stops';
        }
        $config = $this->connector_config($connector);
        if (is_wp_error($config)) {
            return $config;
        }
        $exportReq = new WP_REST_Request('GET', '/');
        foreach (['resource','client_id','project_id','date_from','date_to','limit','page'] as $param) {
            $value = $req->get_param($param);
            if ($value !== null && $value !== '') {
                $exportReq->set_param($param, $value);
            }
        }
        $exportReq->set_param('resource', $resource);
        $exportRes = $this->export($exportReq);
        if (is_wp_error($exportRes)) {
            return $exportRes;
        }
        $payload = $exportRes->get_data();
        $response = wp_remote_post($config['url'], [
            'timeout' => $config['timeout'],
            'headers' => array_merge(['Content-Type' => 'application/json'], $config['headers']),
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) {
            IntegrationPlatform::log($connector, 'push', 'error', ['resource' => $resource], $response->get_error_message());
            return new WP_Error('push_failed', 'Envio falhou: ' . $response->get_error_message(), ['status' => 502]);
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $ok = $code >= 200 && $code < 300;
        IntegrationPlatform::log($connector, 'push', $ok ? 'success' : 'error', ['resource' => $resource, 'http_code' => $code, 'count' => count($payload['items'] ?? [])]);
        return new WP_REST_Response([
            'ok' => $ok,
            'connector' => $connector,
            'resource' => $resource,
            'http_code' => $code,
            'items_sent' => count($payload['items'] ?? []),
            'body_sample' => substr((string)wp_remote_retrieve_body($response), 0, 1000),
        ], $ok ? 200 : 502);
    }

    private function connector_config(string $connector) {
        $opts = IntegrationPlatform::options();
        $timeout = max(5, min(120, (int)($opts['sync_timeout'] ?? 25)));
        switch ($connector) {
            case 'powerbi':
                if (empty($opts['powerbi_enabled']) || empty($opts['powerbi_push_url'])) {
                    return new WP_Error('connector_disabled', 'Power BI não configurado.', ['status' => 400]);
                }
                $headers = [];
                if (($opts['powerbi_auth_type'] ?? 'bearer') === 'bearer' && !empty($opts['powerbi_token'])) {
                    $headers['Authorization'] = 'Bearer ' . $opts['powerbi_token'];
                }
                return ['url' => $opts['powerbi_push_url'], 'headers' => $headers, 'timeout' => $timeout];
            case 'gcloud':
                if (empty($opts['gcloud_enabled']) || empty($opts['gcloud_push_url'])) {
                    return new WP_Error('connector_disabled', 'Google Cloud não configurado.', ['status' => 400]);
                }
                return ['url' => $opts['gcloud_push_url'], 'headers' => IntegrationPlatform::parse_auth_header((string)$opts['gcloud_auth_header']), 'timeout' => $timeout];
            case 'azure':
                if (empty($opts['azure_enabled']) || empty($opts['azure_push_url'])) {
                    return new WP_Error('connector_disabled', 'Azure não configurado.', ['status' => 400]);
                }
                return ['url' => $opts['azure_push_url'], 'headers' => IntegrationPlatform::parse_auth_header((string)$opts['azure_auth_header']), 'timeout' => $timeout];
            default:
                return new WP_Error('bad_request', 'Connector inválido.', ['status' => 400]);
        }
    }

    public function geocode(WP_REST_Request $req){
        $addr = sanitize_text_field($req->get_param('q'));
        if (!$addr) return new WP_Error('bad_request','Parâmetro "q" em falta', ['status'=>400]);

        $cache_sec = max(0, absint($req->get_param('cache_sec') ?? 0));
        $cache_key = $cache_sec ? 'routespro_geocode_'.md5($addr) : null;

        if ($cache_sec && ($cached = get_transient($cache_key))) {
            return new WP_REST_Response($cached, 200);
        }

        $maps = MapsFactory::make();
        if (!$maps) return new WP_Error('no_provider','Fornecedor de mapas não configurado em Settings.', ['status'=>400]);

        try {
            $res = $maps->geocode($addr);
        } catch (\Throwable $e) {
            return new WP_Error('geocode_failed','Geocodificação falhou: '.$e->getMessage(), ['status'=>502]);
        }

        if (!$res) return new WP_Error('geocode_failed','Geocodificação sem resultados.', ['status'=>404]);

        if ($cache_sec) set_transient($cache_key, $res, $cache_sec);
        return new WP_REST_Response($res, 200);
    }

    public function matrix(WP_REST_Request $req){
        $p = $req->get_json_params() ?: [];
        $origins = array_map(function($x){
            return ['lat'=>isset($x['lat']) ? (float)$x['lat'] : null, 'lng'=>isset($x['lng']) ? (float)$x['lng'] : null];
        }, is_array($p['origins'] ?? null) ? $p['origins'] : []);

        $dest = array_map(function($x){
            return ['lat'=>isset($x['lat']) ? (float)$x['lat'] : null, 'lng'=>isset($x['lng']) ? (float)$x['lng'] : null];
        }, is_array($p['destinations'] ?? null) ? $p['destinations'] : []);

        if (!$origins || !$dest) return new WP_Error('bad_request','"origins" e/ou "destinations" em falta', ['status'=>400]);

        $cache_sec = max(0, absint($p['cache_sec'] ?? 0));
        $cache_key = $cache_sec ? 'routespro_matrix_'.md5(wp_json_encode([$origins,$dest])) : null;
        if ($cache_sec && ($cached = get_transient($cache_key))) {
            return new WP_REST_Response($cached, 200);
        }

        $maps = MapsFactory::make();
        if (!$maps) return new WP_Error('no_provider','Fornecedor de mapas não configurado', ['status'=>400]);

        try {
            $res = $maps->distanceMatrix($origins, $dest);
        } catch (\Throwable $e) {
            return new WP_Error('matrix_failed','Distance Matrix falhou: '.$e->getMessage(), ['status'=>502]);
        }

        if (!$res) return new WP_Error('matrix_failed','Sem resposta da Distance Matrix.', ['status'=>502]);

        if ($cache_sec) set_transient($cache_key, $res, $cache_sec);
        return new WP_REST_Response($res, 200);
    }

    public function ai_suggest(WP_REST_Request $req){
        $p = $req->get_json_params() ?: [];
        $raw_context = (string)($p['context'] ?? '');
        if (strlen($raw_context) > 20000) {
            $raw_context = substr($raw_context, 0, 20000);
        }
        $context = wp_kses_post(str_replace(["\r\n","\r"], "\n", $raw_context));
        $task  = sanitize_text_field($p['task'] ?? 'route_notes');
        $temp  = isset($p['temperature']) ? floatval($p['temperature']) : 0.2;
        $temp  = max(0.0, min(1.0, $temp));
        $max_tokens = isset($p['max_tokens']) ? intval($p['max_tokens']) : 500;
        $max_tokens = max(50, min(2000, $max_tokens));
        $model = sanitize_text_field($p['model'] ?? '');

        $ai = AIFactory::make();
        if (!$ai) return new WP_Error('no_ai','Fornecedor de IA não configurado em Settings.', ['status'=>400]);

        $prompt = "Contexto:\n".$context."\n\n".
                  "Tarefa: ".$task."\n".
                  "Consignas: responde em português, direto e prático. Evita floreados e repetições. Se precisares de lista, usa bullets curtos.";

        try {
            $args = ['max_tokens'=>$max_tokens, 'temperature'=>$temp];
            if ($model) $args['model'] = $model;
            $out = $ai->complete($prompt, $args);
        } catch (\Throwable $e) {
            return new WP_Error('ai_failed','IA sem resposta: '.$e->getMessage(), ['status'=>502]);
        }

        if (!$out) return new WP_Error('ai_failed','IA devolveu resultado vazio.', ['status'=>502]);
        return new WP_REST_Response(['text'=>$out], 200);
    }
}
