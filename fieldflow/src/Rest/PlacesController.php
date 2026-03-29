<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use RoutesPro\Admin\Settings;

if (!defined('ABSPATH')) exit;

class PlacesController {
    const NS = 'routespro/v1';

    public function register_routes(): void {
        register_rest_route(self::NS, '/places/search', [[
            'methods' => 'GET',
            'callback' => [$this, 'search'],
            'permission_callback' => function(){ return current_user_can('routespro_manage'); },
        ]]);
    }

    public function search(WP_REST_Request $req) {
        $key = Settings::get('google_maps_key', '');
        if (!$key) return new WP_Error('missing_google_key', 'Google Maps Key não configurada.', ['status' => 400]);

        $district = sanitize_text_field($req->get_param('district') ?: '');
        $county = sanitize_text_field($req->get_param('county') ?: '');
        $city = sanitize_text_field($req->get_param('city') ?: '');
        $category_id = absint($req->get_param('category_id') ?: 0);

        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $category = $category_id ? $wpdb->get_var($wpdb->prepare("SELECT name FROM {$px}categories WHERE id=%d LIMIT 1", $category_id)) : '';
        $pieces = array_filter([$category ?: 'estabelecimentos comerciais', $city, $county, $district, 'Portugal']);
        $query = implode(', ', $pieces);
        $url = add_query_arg([
            'query' => $query,
            'key' => $key,
            'language' => 'pt-PT',
            'region' => 'pt',
        ], 'https://maps.googleapis.com/maps/api/place/textsearch/json');

        $res = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($res)) return new WP_Error('google_failed', $res->get_error_message(), ['status' => 502]);
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 400) return new WP_Error('google_failed', 'Falha na chamada ao Google Places.', ['status' => 502]);
        if (($body['status'] ?? '') === 'REQUEST_DENIED') return new WP_Error('google_denied', $body['error_message'] ?? 'Pedido recusado pelo Google.', ['status' => 502]);

        $items = [];
        foreach (($body['results'] ?? []) as $r) {
            $items[] = [
                'name' => $r['name'] ?? '',
                'address' => $r['formatted_address'] ?? '',
                'lat' => $r['geometry']['location']['lat'] ?? null,
                'lng' => $r['geometry']['location']['lng'] ?? null,
                'place_id' => $r['place_id'] ?? '',
                'source' => 'google',
            ];
        }
        return new WP_REST_Response(['query' => $query, 'items' => $items], 200);
    }
}
