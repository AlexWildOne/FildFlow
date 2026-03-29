<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use RoutesPro\Admin\Settings;

if (!defined('ABSPATH')) { exit; }

class OptimizeController {
    const NS = 'routespro/v1';

    public function register_routes() {
        register_rest_route(self::NS, '/optimize', [
            'methods'  => 'POST',
            'callback' => [$this, 'optimize'],
            'permission_callback' => function(){ return current_user_can('routespro_manage'); }
        ]);
    }

    /**
     * POST /routespro/v1/optimize
     * Body JSON:
     * {
     *   location_ids: [int,...],           // obrigatório (>=2 com lat/lng válidos)
     *   start_id?: int,                    // opcional (tem de estar em location_ids)
     *   end_id?: int,                      // opcional (tem de estar em location_ids; se omitido e roundtrip=true, fim = start)
     *   roundtrip?: bool,                  // default: false
     *   mode?: "auto"|"tsp"|"nn_2opt"      // default: "auto" (usa externo se houver, senão fallback local)
     * }
     * Resposta: { order: [location_id,...] }
     */
    public function optimize(WP_REST_Request $req) {
        global $wpdb; 
        $px = $wpdb->prefix . 'routespro_';

        $p = $req->get_json_params() ?: [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $p['location_ids'] ?? []))));
        if (count($ids) < 2) {
            return new WP_Error('bad_request', 'location_ids em falta (mínimo 2)', ['status'=>400]);
        }

        $start_id  = isset($p['start_id']) ? absint($p['start_id']) : 0;
        $end_id    = isset($p['end_id'])   ? absint($p['end_id'])   : 0;
        $roundtrip = !empty($p['roundtrip']);
        $mode      = in_array(($p['mode'] ?? 'auto'), ['auto','tsp','nn_2opt'], true) ? $p['mode'] : 'auto';

        // Se start_id/end_id fornecidos, têm de existir em $ids
        if ($start_id && !in_array($start_id, $ids, true)) {
            return new WP_Error('bad_request', 'start_id não pertence a location_ids', ['status'=>400]);
        }
        if ($end_id && !in_array($end_id, $ids, true)) {
            return new WP_Error('bad_request', 'end_id não pertence a location_ids', ['status'=>400]);
        }
        if ($roundtrip && !$start_id && $end_id) {
            // roundtrip + end_id sem start_id é ambíguo; obriga start_id
            return new WP_Error('bad_request', 'roundtrip com end_id requer start_id', ['status'=>400]);
        }

        // Buscar coordenadas
        $in = implode(',', array_map('intval', $ids));
        $rows = $wpdb->get_results("SELECT id, lat, lng FROM {$px}locations WHERE id IN ($in)", ARRAY_A);

        $coords = []; // id => [lat,lng]
        foreach ($rows as $r) {
            $lat = isset($r['lat']) ? (float)$r['lat'] : null;
            $lng = isset($r['lng']) ? (float)$r['lng'] : null;
            if ($lat !== null && $lng !== null) {
                $coords[(int)$r['id']] = [$lat, $lng];
            }
        }
        // Precisamos de pelo menos 2 com coordenadas
        if (count($coords) < 2) {
            return new WP_Error('bad_request', 'Locais sem coordenadas suficientes', ['status'=>400]);
        }

        // Filtra ids sem coordenadas para não estragar a otimização
        $ids_with_coords = array_values(array_intersect($ids, array_keys($coords)));

        // Ancoragem: se start/end fornecidos mas algum não tiver coords válidas, erro
        if ($start_id && !isset($coords[$start_id])) {
            return new WP_Error('bad_request', 'start_id sem coordenadas', ['status'=>400]);
        }
        if ($end_id && !isset($coords[$end_id])) {
            return new WP_Error('bad_request', 'end_id sem coordenadas', ['status'=>400]);
        }

        // Tenta otimizador externo se configurado e "auto" ou "tsp"
        $url    = Settings::get('optimizer_url');
        $apiKey = Settings::get('optimizer_api_key');

        if ($url && ($mode === 'auto' || $mode === 'tsp')) {
            $payload = [
                'points'    => array_map(function($id) use ($coords){
                    return ['id'=>$id, 'lat'=>$coords[$id][0], 'lng'=>$coords[$id][1]];
                }, $ids_with_coords),
                'solve'     => 'tsp',
                'roundtrip' => (bool)$roundtrip,
            ];
            if ($start_id) $payload['start_id'] = $start_id;
            if ($end_id)   $payload['end_id']   = $end_id;

            $headers = ['Content-Type' => 'application/json'];
            if (!empty($apiKey)) {
                $headers['Authorization'] = 'Bearer '.$apiKey;
            }

            $res = wp_remote_post($url, [
                'headers' => $headers,
                'body'    => wp_json_encode($payload),
                'timeout' => 25,
            ]);

            if (!is_wp_error($res)) {
                $code = wp_remote_retrieve_response_code($res);
                $body = json_decode(wp_remote_retrieve_body($res), true);
                if ($code < 300 && is_array($body)) {
                    $order = array_values(array_map('absint', $body['order'] ?? []));
                    // Normaliza e completa (não perder nenhum id)
                    $order = $this->normalizeOrder($order, $ids_with_coords, $start_id, $end_id, $roundtrip);
                    if ($order) {
                        // Reinsere ids sem coordenadas no fim, na ordem original
                        $missing = array_values(array_diff($ids, $ids_with_coords));
                        $final   = array_merge($order, $missing);
                        return new WP_REST_Response(['order'=>$final], 200);
                    }
                }
            }
            // Caso falhe, cai no fallback local
        }

        // Fallback local: NN + 2-opt com haversine
        $order_local = $this->solveNearestNeighbor2Opt($ids_with_coords, $coords, $start_id, $end_id, $roundtrip);
        $order_local = $this->normalizeOrder($order_local, $ids_with_coords, $start_id, $end_id, $roundtrip);

        // Adiciona ids sem coordenadas ao fim, mantendo ordem original de $ids
        $missing = array_values(array_diff($ids, $ids_with_coords));
        $final   = array_merge($order_local, $missing);

        return new WP_REST_Response(['order'=>$final], 200);
    }

    /**
     * Garante que:
     * - contém exatamente todos os ids de $ids_with_coords (sem extras)
     * - respeita start_id/end_id/roundtrip quando fornecidos
     */
    private function normalizeOrder(array $order, array $ids_with_coords, int $start_id, int $end_id, bool $roundtrip): array {
        // Mantém apenas ids válidos e únicos
        $seen = [];
        $order = array_values(array_filter($order, function($id) use (&$seen, $ids_with_coords){
            if (!in_array($id, $ids_with_coords, true)) return false;
            if (isset($seen[$id])) return false;
            $seen[$id] = true;
            return true;
        }));

        // Completa ids em falta, na ordem de $ids_with_coords
        foreach ($ids_with_coords as $id) {
            if (!in_array($id, $order, true)) $order[] = $id;
        }

        if (!$order) return [];

        // Respeitar start_id
        if ($start_id) {
            $pos = array_search($start_id, $order, true);
            if ($pos !== false) {
                // rota linear: roda o array para começar em start
                $order = array_values(array_merge(array_slice($order, $pos), array_slice($order, 0, $pos)));
            }
        }

        // Respeitar end_id (se diferente de start)
        if ($end_id && (!$start_id || $end_id !== $start_id || !$roundtrip)) {
            // Se roundtrip=true e end==start, já está ok.
            if ($end_id !== $order[count($order)-1]) {
                // Se end existir noutro ponto, trazê-lo para o fim mantendo ordem relativa
                $order = array_values(array_filter($order, fn($x) => $x !== $end_id));
                $order[] = $end_id;
            }
        }

        // Se roundtrip=true e start_id definido, termine em start
        if ($roundtrip && $start_id) {
            // rota representada como sequência sem repetir o último ponto (o front pode decidir fechar o ciclo)
            // Aqui apenas garantimos que começa em start; o "fecho" é implícito.
            // (Se quiseres repetir o start no fim, descomenta:)
            // if (end($order) !== $start_id) { $order[] = $start_id; }
        }

        return $order;
    }

    /**
     * NN + 2-opt com distância haversine (mais realista que euclidiana em graus)
     */
    private function solveNearestNeighbor2Opt(array $ids, array $coords, int $start_id, int $end_id, bool $roundtrip): array {
        if (!$ids) return [];

        $dist = function(int $a, int $b) use ($coords): float {
            [$lat1, $lng1] = $coords[$a];
            [$lat2, $lng2] = $coords[$b];
            $R = 6371000.0; // raio médio da Terra (m)
            $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
            $dphi = deg2rad($lat2 - $lat1);
            $dlmb = deg2rad($lng2 - $lng1);
            $h = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlmb/2)**2;
            return 2*$R*asin(min(1, sqrt($h)));
        };

        // Escolhe start
        $start = $start_id ?: $ids[0];

        $unvisited = $ids;
        // garante que start está dentro
        $unvisited = array_values(array_diff($unvisited, [$start]));
        $path = [$start];

        // NN (vizinho mais próximo)
        while ($unvisited) {
            $last = end($path);
            $best = null; $bestd = PHP_FLOAT_MAX; $bestk = null;
            foreach ($unvisited as $k => $cand) {
                $d = $dist($last, $cand);
                if ($d < $bestd) { $bestd = $d; $best = $cand; $bestk = $k; }
            }
            $path[] = $best;
            array_splice($unvisited, $bestk, 1);
        }

        // Se end_id definido (e diferente do start ou roundtrip=false), força-o a ser o último
        if ($end_id && ($end_id !== $start || !$roundtrip)) {
            $pos = array_search($end_id, $path, true);
            if ($pos !== false && $pos !== count($path)-1) {
                // move end_id para o fim preservando ordem
                $path = array_values(array_filter($path, fn($x)=>$x !== $end_id));
                $path[] = $end_id;
            }
        }

        // 2-opt (não mexe no primeiro ponto; respeita end se existir no fim)
        $N = count($path);
        $improve = true;
        $lockStart = 1; // não altera posição 0 (start)
        $lockEnd   = ($end_id && ($end_id !== $start || !$roundtrip)) ? 1 : 0; // se end fixo no fim, reserva última posição

        while ($improve) {
            $improve = false;
            $maxI = $N - 2 - $lockEnd;
            for ($i = $lockStart; $i <= $maxI; $i++) {
                $maxK = $N - 2;
                for ($k = $i + 1; $k <= $maxK; $k++) {
                    $a = $path[$i - 1];
                    $b = $path[$i];
                    $c = $path[$k];
                    $d = $path[$k + 1] ?? null;
                    if ($d === null) continue; // precisa de segmento seguinte

                    $delta = ($dist($a,$b) + $dist($c,$d)) - ($dist($a,$c) + $dist($b,$d));
                    if ($delta > 1e-6) {
                        $path = array_merge(
                            array_slice($path, 0, $i),
                            array_reverse(array_slice($path, $i, $k - $i + 1)),
                            array_slice($path, $k + 1)
                        );
                        $improve = true;
                    }
                }
            }
        }

        return array_values($path);
    }
}
