<?php
namespace RoutesPro\Services;

if (!defined('ABSPATH')) exit;

class RouteSnapshotService {
    public static function capture(int $route_id, ?int $route_stop_id, int $location_id): bool {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $loc = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, c.name AS category_name, sc.name AS subcategory_name
             FROM {$px}locations l
             LEFT JOIN {$px}categories c ON c.id=l.category_id
             LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id
             WHERE l.id=%d LIMIT 1", $location_id
        ), ARRAY_A);
        if (!$loc) return false;
        $wpdb->insert($px.'route_location_snapshot', [
            'route_id' => $route_id,
            'route_stop_id' => $route_stop_id,
            'location_id' => $location_id,
            'name' => $loc['name'] ?? '',
            'address' => $loc['address'] ?? '',
            'district' => $loc['district'] ?? '',
            'county' => $loc['county'] ?? '',
            'city' => $loc['city'] ?? '',
            'parish' => $loc['parish'] ?? '',
            'postal_code' => $loc['postal_code'] ?? '',
            'country' => $loc['country'] ?? '',
            'category_id' => $loc['category_id'] ?: null,
            'category_name' => $loc['category_name'] ?? '',
            'subcategory_id' => $loc['subcategory_id'] ?: null,
            'subcategory_name' => $loc['subcategory_name'] ?? '',
            'contact_person' => $loc['contact_person'] ?? '',
            'phone' => $loc['phone'] ?? '',
            'email' => $loc['email'] ?? '',
            'website' => $loc['website'] ?? '',
            'lat' => $loc['lat'] !== null ? (float)$loc['lat'] : null,
            'lng' => $loc['lng'] !== null ? (float)$loc['lng'] : null,
            'place_id' => $loc['place_id'] ?? '',
            'source' => $loc['source'] ?? '',
            'meta_json' => wp_json_encode(['captured_from' => 'route_form']),
        ]);
        $wpdb->update($px.'locations', ['last_seen_at' => current_time('mysql')], ['id' => $location_id]);
        return $wpdb->last_error === '';
    }
}
