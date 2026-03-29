<?php
namespace RoutesPro\Services;

if (!defined('ABSPATH')) exit;

class LocationDeduplicator {

    private static function location_columns(): array {
        static $columns = null;
        if (is_array($columns)) return $columns;
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$px}locations", ARRAY_A) ?: [];
        $columns = array_map(static function($row){ return (string)($row['Field'] ?? ''); }, $rows);
        return $columns;
    }

    public static function filter_location_payload(array $data): array {
        $columns = array_flip(array_filter(self::location_columns()));
        if (!$columns) return $data;
        return array_intersect_key($data, $columns);
    }

    public static function score_row(array $row): int {
        $score = 0;
        foreach (['name','address','district','county','city','contact_person','phone','email','place_id','external_ref'] as $field) {
            if (!empty($row[$field])) $score += 2;
        }
        if (!empty($row['lat']) && !empty($row['lng'])) $score += 3;
        if (!empty($row['updated_at'])) $score += 1;
        return $score;
    }

    public static function canonical_key(array $row): string {
        $placeId = sanitize_text_field((string)($row['place_id'] ?? ''));
        if ($placeId !== '') return 'place_id:' . strtolower($placeId);
        $externalRef = sanitize_text_field((string)($row['external_ref'] ?? ''));
        if ($externalRef !== '') return 'external_ref:' . strtolower($externalRef);
        $name = self::normalize_text((string)($row['name'] ?? ''));
        $addr = self::normalize_text((string)($row['address'] ?? ''));
        if ($name !== '' && $addr !== '') return 'name_address:' . $name . '|' . $addr;
        $id = (int)($row['id'] ?? 0);
        return 'id:' . $id;
    }

    public static function dedupe_rows(array $rows): array {
        $seen = [];
        foreach ($rows as $row) {
            $key = self::canonical_key((array)$row);
            if (!isset($seen[$key])) {
                $seen[$key] = $row;
                continue;
            }
            $current = (array)$seen[$key];
            $candidate = (array)$row;
            if (self::score_row($candidate) >= self::score_row($current)) {
                $seen[$key] = array_merge($current, $candidate);
            }
        }
        return array_values($seen);
    }

    public static function merge_all_groups(): int {
        $groups = self::find_groups();
        $mergedGroups = 0;
        foreach ($groups as $group) {
            $masterId = (int)($group['master_id'] ?? 0);
            $duplicateIds = array_values(array_filter(array_map(function($item) use ($masterId){
                $id = (int)($item['id'] ?? 0);
                return $id && $id !== $masterId ? $id : 0;
            }, (array)($group['items'] ?? []))));
            if (!$masterId || !$duplicateIds) continue;
            self::merge_group($masterId, $duplicateIds);
            $mergedGroups++;
        }
        return $mergedGroups;
    }

    public static function normalize_phone(string $phone): string {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    public static function normalize_text(string $value): string {
        $value = remove_accents(wp_strip_all_tags((string)$value));
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        return $value ?: '';
    }

    public static function find_match(array $data, int $exclude_id = 0): array {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';

        $checks = [];
        $place_id = sanitize_text_field((string)($data['place_id'] ?? ''));
        $external_ref = sanitize_text_field((string)($data['external_ref'] ?? ''));
        $name = sanitize_text_field((string)($data['name'] ?? ''));
        $address = sanitize_text_field((string)($data['address'] ?? ''));
        $client_id = absint($data['client_id'] ?? 0);
        $project_id = absint($data['project_id'] ?? 0);

        if ($place_id !== '') $checks[] = ['place_id', $place_id, 'place_id'];
        if ($external_ref !== '') $checks[] = ['external_ref', $external_ref, 'external_ref'];
        if ($name !== '' && $address !== '') $checks[] = [['name','address'], [$name, $address], 'name_address'];

        foreach ($checks as $check) {
            $where = [];
            $args = [];

            if (is_array($check[0])) {
                $where[] = 'name=%s';
                $args[] = $check[1][0];
                $where[] = 'address=%s';
                $args[] = $check[1][1];
            } else {
                $where[] = "{$check[0]}=%s";
                $args[] = $check[1];
            }

            if ($client_id) {
                $where[] = 'client_id=%d';
                $args[] = $client_id;
            }
            if ($project_id) {
                $where[] = 'project_id=%d';
                $args[] = $project_id;
            }
            if ($exclude_id) {
                $where[] = 'id<>%d';
                $args[] = $exclude_id;
            }

            $sql = "SELECT * FROM {$px}locations WHERE " . implode(' AND ', $where) . ' LIMIT 1';
            $row = $wpdb->get_row($wpdb->prepare($sql, ...$args), ARRAY_A);
            if ($row) return ['id' => (int)$row['id'], 'reason' => $check[2], 'row' => $row];
        }
        return ['id' => 0, 'reason' => '', 'row' => null];
    }

    public static function merge_payload(array $existing, array $incoming): array {
        $merged = $existing;
        foreach ($incoming as $k => $v) {
            if ($v === null) continue;
            if (is_string($v) && trim($v) === '') continue;
            $merged[$k] = $v;
        }
        $merged['updated_at'] = current_time('mysql');
        return $merged;
    }

    public static function upsert(array $data, int $exclude_id = 0, bool $replace_existing = true): array {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $match = self::find_match($data, $exclude_id);
        if ($match['id']) {
            if ($replace_existing) {
                $merged = self::filter_location_payload(self::merge_payload((array)$match['row'], $data));
                unset($merged['id']);
                $wpdb->update($px.'locations', $merged, ['id' => $match['id']]);
            }
            return ['id' => $match['id'], 'existing' => true, 'reason' => $match['reason']];
        }
        $wpdb->insert($px.'locations', self::filter_location_payload($data));
        return ['id' => (int)$wpdb->insert_id, 'existing' => false, 'reason' => ''];
    }

    public static function find_groups(): array {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $rows = $wpdb->get_results("SELECT * FROM {$px}locations ORDER BY id ASC", ARRAY_A) ?: [];
        $groups = [];
        foreach ($rows as $row) {
            $keys = [];
            if (!empty($row['place_id'])) $keys[] = 'place_id:' . $row['place_id'];
            $name = self::normalize_text((string)($row['name'] ?? ''));
            $addr = self::normalize_text((string)($row['address'] ?? ''));
            if ($name !== '' && $addr !== '') $keys[] = 'name_address:' . $name . '|' . $addr;
            foreach (array_unique($keys) as $key) {
                $groups[$key][] = $row;
            }
        }
        $out = [];
        foreach ($groups as $key => $items) {
            $ids = array_values(array_unique(array_map(fn($x)=>(int)$x['id'], $items)));
            if (count($ids) < 2) continue;
            usort($items, fn($a,$b)=> (int)$a['id'] <=> (int)$b['id']);
            $master = end($items);
            $out[] = [
                'key' => $key,
                'reason' => strtok($key, ':'),
                'master_id' => (int)$master['id'],
                'items' => $items,
            ];
        }
        usort($out, fn($a,$b)=> count($b['items']) <=> count($a['items']));
        return $out;
    }

    public static function merge_group(int $master_id, array $duplicate_ids): void {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $master = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}locations WHERE id=%d", $master_id), ARRAY_A);
        if (!$master) return;
        foreach ($duplicate_ids as $dup_id) {
            $dup_id = absint($dup_id);
            if (!$dup_id || $dup_id === $master_id) continue;
            $dup = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}locations WHERE id=%d", $dup_id), ARRAY_A);
            if (!$dup) continue;
            $merged = self::merge_payload($master, $dup);
            unset($merged['id']);
            $wpdb->update($px.'locations', $merged, ['id' => $master_id]);
            $wpdb->update($px.'route_stops', ['location_id' => $master_id], ['location_id' => $dup_id]);
            $wpdb->update($px.'route_location_snapshot', ['location_id' => $master_id], ['location_id' => $dup_id]);
            if ($wpdb->get_var("SHOW TABLES LIKE '{$px}campaign_locations'") === $px.'campaign_locations') {
                $wpdb->update($px.'campaign_locations', ['location_id' => $master_id], ['location_id' => $dup_id]);
                $wpdb->query("DELETE cl1 FROM {$px}campaign_locations cl1 INNER JOIN {$px}campaign_locations cl2 ON cl1.project_id = cl2.project_id AND cl1.location_id = cl2.location_id AND cl1.id > cl2.id");
            }
            $wpdb->delete($px.'locations', ['id' => $dup_id]);
            $master = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}locations WHERE id=%d", $master_id), ARRAY_A) ?: $master;
        }
    }
}
