<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

class CategoriesController {
    const NS = 'routespro/v1';

    public function register_routes(): void {
        register_rest_route(self::NS, '/categories', [[
            'methods' => 'GET',
            'callback' => [$this, 'list_categories'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || current_user_can('routespro_execute'); },
        ]]);
    }

    public function list_categories(WP_REST_Request $req): WP_REST_Response {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $rows = $wpdb->get_results("SELECT id,parent_id,name,slug,type,is_active FROM {$px}categories WHERE is_active=1 ORDER BY sort_order ASC, name ASC", ARRAY_A) ?: [];
        $seen = []; $items = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string)($row['name'] ?: $row['slug'] ?: sanitize_title($row['name'])))) . '|' . intval($row['parent_id'] ?: 0);
            if (isset($seen[$key])) continue;
            $seen[$key] = 1;
            $items[] = $row;
        }
        return new WP_REST_Response(['items' => $items], 200);
    }
}
