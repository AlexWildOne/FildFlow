<?php
namespace RoutesPro\Services;

use RoutesPro\Admin\Integrations;

class IntegrationPlatform {
    public static function options(): array {
        return Integrations::get();
    }

    public static function log(string $connector, string $action, string $status, array $payload = [], string $message = ''): void {
        global $wpdb;
        $table = $wpdb->prefix . 'routespro_integration_logs';
        $wpdb->insert($table, [
            'connector' => sanitize_text_field($connector),
            'action' => sanitize_text_field($action),
            'status' => sanitize_text_field($status),
            'message' => sanitize_text_field($message),
            'payload_json' => wp_json_encode($payload),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function parse_auth_header(string $header): array {
        $out = [];
        $header = trim($header);
        if ($header === '' || strpos($header, ':') === false) {
            return $out;
        }
        [$name, $value] = explode(':', $header, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name !== '' && $value !== '') {
            $out[$name] = $value;
        }
        return $out;
    }
}
