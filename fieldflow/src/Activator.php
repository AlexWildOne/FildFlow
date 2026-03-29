<?php
namespace RoutesPro;
use wpdb;

class Activator {
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $prefix = $wpdb->prefix . 'routespro_';

        // Evitar JSON nativo (dbDelta tem incompatibilidades) -> LONGTEXT
        $tables = [];

        $tables[] = "CREATE TABLE {$prefix}clients (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            taxid VARCHAR(64) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(64) NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $charset;";

        
        $tables[] = "CREATE TABLE {$prefix}email_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email_type VARCHAR(64) NOT NULL DEFAULT 'system',
            context_key VARCHAR(64) NULL,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            route_id BIGINT UNSIGNED NULL,
            sender_user_id BIGINT UNSIGNED NULL,
            recipient_user_id BIGINT UNSIGNED NULL,
            recipient_email VARCHAR(255) NULL,
            recipient_name VARCHAR(255) NULL,
            message_kind VARCHAR(64) NULL,
            subject VARCHAR(255) NOT NULL,
            body LONGTEXT NULL,
            meta_json LONGTEXT NULL,
            mail_result VARCHAR(32) NOT NULL DEFAULT 'sent',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY email_type (email_type),
            KEY context_key (context_key),
            KEY client (client_id),
            KEY project (project_id),
            KEY route (route_id),
            KEY sender (sender_user_id),
            KEY recipient_user (recipient_user_id),
            KEY created_at (created_at)
        ) $charset;";

$tables[] = "CREATE TABLE {$prefix}projects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(32) DEFAULT 'active',
            meta_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY client (client_id)
        ) $charset;";

        $tables[] = "CREATE TABLE {$prefix}locations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            address TEXT NULL,
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            window_start TIME NULL,
            window_end TIME NULL,
            service_time_min INT DEFAULT 0,
            tags VARCHAR(255) NULL,
            external_ref VARCHAR(128) NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY client (client_id),
            KEY project (project_id),
            KEY latlng (lat, lng)
        ) $charset;";

        $tables[] = "CREATE TABLE {$prefix}routes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NULL,
            date DATE NOT NULL,
            status VARCHAR(32) DEFAULT 'draft',
            owner_user_id BIGINT UNSIGNED NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY date_idx (date),
            KEY owner (owner_user_id),
            KEY client (client_id),
            KEY project (project_id)
        ) $charset;";

            // NOTA: esta tabela inclui todos os campos já usados nos controllers (arrived_at, departed_at, etc.)
        $tables[] = "CREATE TABLE {$prefix}route_stops (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            route_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            seq INT NOT NULL DEFAULT 0,
            planned_arrival DATETIME NULL,
            planned_departure DATETIME NULL,
            -- reporte/execução
            arrived_at DATETIME NULL,
            departed_at DATETIME NULL,
            duration_s INT NULL,
            status VARCHAR(32) DEFAULT 'pending',
            note TEXT NULL,
            fail_reason VARCHAR(255) NULL,
            photo_url TEXT NULL,
            signature_data LONGTEXT NULL,
            qty DOUBLE NULL,
            weight DOUBLE NULL,
            volume DOUBLE NULL,
            real_lat DECIMAL(10,7) NULL,
            real_lng DECIMAL(10,7) NULL,
            meta_json LONGTEXT NULL,
            KEY route (route_id),
            KEY location (location_id),
            KEY seq (seq),
            KEY status_idx (status)
        ) $charset;";


        $tables[] = "CREATE TABLE {$prefix}project_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(32) DEFAULT 'owner',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_project_user (project_id, user_id),
            KEY project (project_id),
            KEY user (user_id),
            KEY role_idx (role)
        ) $charset;";

        $tables[] = "CREATE TABLE {$prefix}assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            route_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(32) DEFAULT 'driver',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_route_user (route_id, user_id),
            KEY route (route_id),
            KEY user (user_id),
            KEY role_idx (role)
        ) $charset;";


        $tables[] = "CREATE TABLE {$prefix}forms (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            schema_json LONGTEXT NULL,
            settings_json LONGTEXT NULL,
            theme_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY status_idx (status),
            KEY updated_idx (updated_at)
        ) $charset;";

        $tables[] = "CREATE TABLE {$prefix}form_submissions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(32) NOT NULL DEFAULT 'submitted',
            meta_json LONGTEXT NULL,
            KEY form_idx (form_id),
            KEY user_idx (user_id),
            KEY submitted_idx (submitted_at)
        ) $charset;";

        $tables[] = "CREATE TABLE {$prefix}form_submission_answers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submission_id BIGINT UNSIGNED NOT NULL,
            question_key VARCHAR(191) NOT NULL,
            question_label VARCHAR(255) NULL,
            value_text LONGTEXT NULL,
            value_number DOUBLE NULL,
            value_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY submission_idx (submission_id),
            KEY question_key (question_key)
        ) $charset;";

        $tables[] = "CREATE TABLE {$prefix}form_bindings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            route_id BIGINT UNSIGNED NULL,
            stop_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NULL,
            mode VARCHAR(32) NOT NULL DEFAULT 'route_and_form',
            priority INT NOT NULL DEFAULT 10,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY form_idx (form_id),
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY route_idx (route_id),
            KEY stop_idx (stop_id),
            KEY location_idx (location_id),
            KEY active_idx (is_active),
            KEY priority_idx (priority)
        ) $charset;";

        $tables[] = "CREATE TABLE {$prefix}events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            route_stop_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(32) NOT NULL,
            payload_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY stop (route_stop_id),
            KEY user (user_id),
            KEY type (event_type),
            KEY created_idx (created_at)
        ) $charset;";

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        // Migrações suaves (para instalações existentes)
        self::migrate($wpdb, $prefix);

        // Versão
        if (!get_option('routespro_version')) {
            add_option('routespro_version', defined('ROUTESPRO_VERSION') ? ROUTESPRO_VERSION : '1.0.0');
        } else {
            update_option('routespro_version', defined('ROUTESPRO_VERSION') ? ROUTESPRO_VERSION : get_option('routespro_version'));
        }

        if (class_exists('\\RoutesPro\\Services\\LocationDeduplicator')) {
            \RoutesPro\Services\LocationDeduplicator::merge_all_groups();
        }

        // Capabilities
        $roles = ['administrator'];
        $caps = ['routespro_manage', 'routespro_execute'];
        foreach ($roles as $r) {
            if ($role = get_role($r)) {
                foreach ($caps as $c) { $role->add_cap($c); }
            }
        }
        if (!get_role('route_agent')) {
            add_role('route_agent', 'Route Agent', [
                'read' => true,
                'routespro_execute' => true
            ]);
        }
    }

    private static function migrate(wpdb $wpdb, string $prefix){
        // Helpers
        $hasCol = function(string $table, string $col) use ($wpdb) {
            $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
            return !empty($row);
        };
        $colType = function(string $table, string $col) use ($wpdb) {
            $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col), ARRAY_A);
            return $row['Type'] ?? null;
        };
        $table = function(string $name) use ($prefix){ return $prefix.$name; };

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table('categories'))) === $table('categories')) {
            $wpdb->query("UPDATE {$table('categories')} SET name='Makro', slug='makro' WHERE LOWER(name)='macro'");
            $wpdb->query("UPDATE {$table('categories')} SET name='Marabuto', slug='marabuto' WHERE LOWER(name)='matarabuto'");
        }

        // locations.client_id -> permitir NULL (antigos podiam ter NOT NULL)
        if ($hasCol($table('locations'), 'client_id')) {
            $row = $wpdb->get_row("SHOW COLUMNS FROM {$table('locations')} LIKE 'client_id'", ARRAY_A);
            if (!empty($row) && stripos($row['Null'], 'NO') !== false) {
                $wpdb->query("ALTER TABLE {$table('locations')} MODIFY client_id BIGINT UNSIGNED NULL");
            }
        }

        // meta_json/payload_json -> LONGTEXT se estiver JSON
        $jsonCols = [
            ['table'=>'clients',      'col'=>'meta_json'],
            ['table'=>'projects',     'col'=>'meta_json'],
            ['table'=>'locations',    'col'=>'meta_json'],
            ['table'=>'routes',       'col'=>'meta_json'],
            ['table'=>'route_stops',  'col'=>'meta_json'],
            ['table'=>'events',       'col'=>'payload_json'],
        ];
        foreach ($jsonCols as $jc) {
            $t = $table($jc['table']);
            if ($hasCol($t, $jc['col'])) {
                $type = $colType($t, $jc['col']);
                if ($type && stripos($type, 'json') !== false) {
                    $wpdb->query("ALTER TABLE {$t} MODIFY {$jc['col']} LONGTEXT NULL");
                }
            }
        }

        // Índice único em assignments (route_id,user_id) — limpar duplicados antes
        $idx = $wpdb->get_var("SHOW INDEX FROM {$table('assignments')} WHERE Key_name='uniq_route_user'");
        if (!$idx) {
            $dupes = $wpdb->get_results("
                SELECT route_id, user_id, COUNT(*) c
                FROM {$table('assignments')}
                GROUP BY route_id, user_id
                HAVING c > 1
            ");
            if ($dupes) {
                foreach ($dupes as $d) {
                    $ids = $wpdb->get_col($wpdb->prepare("
                        SELECT id FROM {$table('assignments')}
                        WHERE route_id=%d AND user_id=%d
                        ORDER BY id ASC
                    ", $d->route_id, $d->user_id));
                    array_shift($ids);
                    if ($ids) {
                        $in = implode(',', array_map('intval', $ids));
                        $wpdb->query("DELETE FROM {$table('assignments')} WHERE id IN ($in)");
                    }
                }
            }
            $wpdb->query("ALTER TABLE {$table('assignments')} ADD UNIQUE KEY uniq_route_user (route_id, user_id)");
        }

        // Índice extra em locations lat/lng
        $latlngIdx = $wpdb->get_var("SHOW INDEX FROM {$table('locations')} WHERE Key_name='latlng'");
        if (!$latlngIdx) {
            $wpdb->query("ALTER TABLE {$table('locations')} ADD KEY latlng (lat,lng)");
        }

        // Índices que ajudam as queries do BO/stats (se faltarem)
        $routesProjectIdx = $wpdb->get_var("SHOW INDEX FROM {$table('routes')} WHERE Key_name='project'");
        if (!$routesProjectIdx) {
            $wpdb->query("ALTER TABLE {$table('routes')} ADD KEY project (project_id)");
        }
        $eventsCreatedIdx = $wpdb->get_var("SHOW INDEX FROM {$table('events')} WHERE Key_name='created_idx'");
        if (!$eventsCreatedIdx) {
            $wpdb->query("ALTER TABLE {$table('events')} ADD KEY created_idx (created_at)");
        }
        if (!$hasCol($table('assignments'), 'is_active')) {
            $wpdb->query("ALTER TABLE {$table('assignments')} ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
        }

        $assignRoleIdx = $wpdb->get_var("SHOW INDEX FROM {$table('assignments')} WHERE Key_name='role_idx'");
        if (!$assignRoleIdx) {
            $wpdb->query("ALTER TABLE {$table('assignments')} ADD KEY role_idx (role)");
        }
        $stopsStatusIdx = $wpdb->get_var("SHOW INDEX FROM {$table('route_stops')} WHERE Key_name='status_idx'");
        if (!$stopsStatusIdx) {
            $wpdb->query("ALTER TABLE {$table('route_stops')} ADD KEY status_idx (status)");
        }

        // ---- Campos recentes em route_stops (compatibilidade com controladores) ----
        $addCol = function(string $name, string $def) use ($wpdb, $table, $hasCol) {
            $t = $table('route_stops');
            if (!$hasCol($t, $name)) {
                $wpdb->query("ALTER TABLE {$t} ADD COLUMN {$def}");
            }
        };
        $addCol('arrived_at',      "arrived_at DATETIME NULL AFTER planned_departure");
        $addCol('departed_at',     "departed_at DATETIME NULL AFTER arrived_at");
        $addCol('duration_s',      "duration_s INT NULL AFTER departed_at");
        $addCol('fail_reason',     "fail_reason VARCHAR(255) NULL AFTER note");
        $addCol('photo_url',       "photo_url TEXT NULL AFTER fail_reason");
        $addCol('signature_data',  "signature_data LONGTEXT NULL AFTER photo_url");
        $addCol('qty',             "qty DOUBLE NULL AFTER signature_data");
        $addCol('weight',          "weight DOUBLE NULL AFTER qty");
        $addCol('volume',          "volume DOUBLE NULL AFTER weight");
        $addCol('real_lat',        "real_lat DECIMAL(10,7) NULL AFTER volume");
        $addCol('real_lng',        "real_lng DECIMAL(10,7) NULL AFTER real_lat");

        // ---- Base comercial v2 ----
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table('form_bindings')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            route_id BIGINT UNSIGNED NULL,
            stop_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NULL,
            mode VARCHAR(32) NOT NULL DEFAULT 'route_and_form',
            priority INT NOT NULL DEFAULT 10,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY form_idx (form_id),
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY route_idx (route_id),
            KEY stop_idx (stop_id),
            KEY location_idx (location_id),
            KEY active_idx (is_active),
            KEY priority_idx (priority)
        ) {$wpdb->get_charset_collate()}");

        if (!$hasCol($table('form_submissions'), 'binding_id')) {
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD COLUMN binding_id BIGINT UNSIGNED NULL AFTER form_id");
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD KEY binding_idx (binding_id)");
        }
        if (!$hasCol($table('form_submissions'), 'client_id')) {
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER binding_id");
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD KEY client_idx (client_id)");
        }
        if (!$hasCol($table('form_submissions'), 'project_id')) {
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER client_id");
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD KEY project_idx (project_id)");
        }
        if (!$hasCol($table('form_submissions'), 'route_id')) {
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD COLUMN route_id BIGINT UNSIGNED NULL AFTER project_id");
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD KEY route_idx (route_id)");
        }
        if (!$hasCol($table('form_submissions'), 'route_stop_id')) {
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD COLUMN route_stop_id BIGINT UNSIGNED NULL AFTER route_id");
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD KEY route_stop_idx (route_stop_id)");
        }
        if (!$hasCol($table('form_submissions'), 'location_id')) {
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER route_stop_id");
            $wpdb->query("ALTER TABLE {$table('form_submissions')} ADD KEY location_idx (location_id)");
        }

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table('categories')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id BIGINT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            type VARCHAR(64) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY parent_idx (parent_id),
            KEY slug_idx (slug),
            KEY type_idx (type),
            KEY active_idx (is_active)
        ) {$wpdb->get_charset_collate()}");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table('route_location_snapshot')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            route_id BIGINT UNSIGNED NOT NULL,
            route_stop_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            address TEXT NULL,
            district VARCHAR(120) NULL,
            county VARCHAR(120) NULL,
            city VARCHAR(120) NULL,
            parish VARCHAR(120) NULL,
            postal_code VARCHAR(32) NULL,
            country VARCHAR(80) NULL,
            category_id BIGINT UNSIGNED NULL,
            category_name VARCHAR(255) NULL,
            subcategory_id BIGINT UNSIGNED NULL,
            subcategory_name VARCHAR(255) NULL,
            contact_person VARCHAR(255) NULL,
            phone VARCHAR(64) NULL,
            email VARCHAR(255) NULL,
            website VARCHAR(255) NULL,
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            place_id VARCHAR(128) NULL,
            source VARCHAR(32) NULL,
            captured_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            meta_json LONGTEXT NULL,
            KEY route_idx (route_id),
            KEY route_stop_idx (route_stop_id),
            KEY location_idx (location_id),
            KEY category_idx (category_id)
        ) {$wpdb->get_charset_collate()}");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table('location_import_batches')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            source VARCHAR(32) NULL DEFAULT 'csv',
            status VARCHAR(32) NOT NULL DEFAULT 'uploaded',
            total_rows INT NOT NULL DEFAULT 0,
            inserted_rows INT NOT NULL DEFAULT 0,
            updated_rows INT NOT NULL DEFAULT 0,
            skipped_rows INT NOT NULL DEFAULT 0,
            error_rows INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            summary_json LONGTEXT NULL,
            KEY status_idx (status),
            KEY created_by_idx (created_by)
        ) {$wpdb->get_charset_collate()}");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table('integration_logs')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            connector VARCHAR(64) NOT NULL,
            action VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'info',
            message TEXT NULL,
            payload_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY connector_idx (connector),
            KEY action_idx (action),
            KEY status_idx (status),
            KEY created_idx (created_at)
        ) {$wpdb->get_charset_collate()}");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table('campaign_locations')} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            priority INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            assigned_to BIGINT UNSIGNED NULL,
            visit_frequency VARCHAR(32) NULL,
            frequency_count INT NOT NULL DEFAULT 1,
            visit_duration_min INT NOT NULL DEFAULT 45,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_project_location (project_id, location_id),
            KEY project_idx (project_id),
            KEY location_idx (location_id),
            KEY active_idx (is_active)
        ) {$wpdb->get_charset_collate()}");
        $addLocCol = function(string $name, string $def) use ($wpdb, $table, $hasCol) {
            $t = $table('locations');
            if (!$hasCol($t, $name)) {
                $wpdb->query("ALTER TABLE {$t} ADD COLUMN {$def}");
            }
        };
        $addLocCol('location_type', "location_type VARCHAR(32) NULL DEFAULT 'pdv' AFTER project_id");
        $addLocCol('district', "district VARCHAR(120) NULL AFTER address");
        $addLocCol('county', "county VARCHAR(120) NULL AFTER district");
        $addLocCol('city', "city VARCHAR(120) NULL AFTER county");
        $addLocCol('parish', "parish VARCHAR(120) NULL AFTER city");
        $addLocCol('postal_code', "postal_code VARCHAR(32) NULL AFTER parish");
        $addLocCol('country', "country VARCHAR(80) NULL DEFAULT 'Portugal' AFTER postal_code");
        $addLocCol('category_id', "category_id BIGINT UNSIGNED NULL AFTER country");
        $addLocCol('subcategory_id', "subcategory_id BIGINT UNSIGNED NULL AFTER category_id");
        $addLocCol('contact_person', "contact_person VARCHAR(255) NULL AFTER subcategory_id");
        $addLocCol('phone', "phone VARCHAR(64) NULL AFTER contact_person");
        $addLocCol('email', "email VARCHAR(255) NULL AFTER phone");
        $addLocCol('website', "website VARCHAR(255) NULL AFTER email");
        $addLocCol('place_id', "place_id VARCHAR(128) NULL AFTER website");
        $addLocCol('source', "source VARCHAR(32) NULL DEFAULT 'manual' AFTER place_id");
        $addLocCol('source_confidence', "source_confidence TINYINT UNSIGNED NULL DEFAULT 50 AFTER source");
        $addLocCol('is_active', "is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER source_confidence");
        $addLocCol('is_validated', "is_validated TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        $addLocCol('last_seen_at', "last_seen_at DATETIME NULL AFTER is_validated");

        $addCampaignCol = function(string $name, string $def) use ($wpdb, $table, $hasCol) {
            $t = $table('campaign_locations');
            if (!$hasCol($t, $name)) {
                $wpdb->query("ALTER TABLE {$t} ADD COLUMN {$def}");
            }
        };
        $addCampaignCol('frequency_count', "frequency_count INT NOT NULL DEFAULT 1 AFTER visit_frequency");
        $addCampaignCol('visit_duration_min', "visit_duration_min INT NOT NULL DEFAULT 45 AFTER frequency_count");

        foreach ([
            ['client_idx', 'client_id'],
            ['project_idx', 'project_id'],
            ['location_type_idx', 'location_type'],
            ['district_idx', 'district'],
            ['county_idx', 'county'],
            ['city_idx', 'city'],
            ['category_idx', 'category_id'],
            ['subcategory_idx', 'subcategory_id'],
            ['place_id_idx', 'place_id'],
            ['email_idx', 'email'],
            ['phone_idx', 'phone'],
            ['validated_idx', 'is_validated'],
            ['active_idx', 'is_active']
        ] as $idxParts) {
            [$idxName, $colName] = $idxParts;
            if (!$wpdb->get_var("SHOW INDEX FROM {$table('locations')} WHERE Key_name='{$idxName}'")) {
                $wpdb->query("ALTER TABLE {$table('locations')} ADD KEY {$idxName} ({$colName})");
            }
        }
        if (!$wpdb->get_var("SHOW INDEX FROM {$table('locations')} WHERE Key_name='commercial_search_idx'")) {
            $wpdb->query("ALTER TABLE {$table('locations')} ADD KEY commercial_search_idx (client_id, project_id, district, county, city, category_id, subcategory_id, is_active)");
        }

        if (!$wpdb->get_var("SHOW INDEX FROM {$table('campaign_locations')} WHERE Key_name='project_location_active_idx'")) {
            $wpdb->query("ALTER TABLE {$table('campaign_locations')} ADD KEY project_location_active_idx (project_id, location_id, is_active)");
        }

        $categorySeeds = [
            ['Horeca', null, 'horeca'],
            ['Retalho', null, 'retalho'],
            ['Saúde', null, 'saude'],
            ['Especializado', null, 'especializado'],
            ['Outros', null, 'outros'],
            ['Restaurante', 'Horeca', 'horeca'],
            ['Café', 'Horeca', 'horeca'],
            ['Bar', 'Horeca', 'horeca'],
            ['Pastelaria', 'Horeca', 'horeca'],
            ['Hotel', 'Horeca', 'horeca'],
            ['Snack-bar', 'Horeca', 'horeca'],
            ['Beach club', 'Horeca', 'horeca'],
            ['Supermercado', 'Retalho', 'retalho'],
            ['Minimercado', 'Retalho', 'retalho'],
            ['Conveniência', 'Retalho', 'retalho'],
            ['Quiosque', 'Retalho', 'retalho'],
            ['Papelaria', 'Retalho', 'retalho'],
            ['Garrafeira', 'Retalho', 'retalho'],
            ['Loja gourmet', 'Retalho', 'retalho'],
            ['Tabacaria', 'Retalho', 'retalho'],
            ['Hipermercados', null, 'retalho'],
            ['Lojas tecnologia', null, 'retalho'],
            ['Cash & Carry', null, 'retalho'],
            ['Continente', 'Hipermercados', 'retalho'],
            ['Continente Bom Dia', 'Hipermercados', 'retalho'],
            ['Pingo Doce', 'Hipermercados', 'retalho'],
            ['Auchan', 'Hipermercados', 'retalho'],
            ['Mercadona', 'Hipermercados', 'retalho'],
            ['E.Leclerc', 'Hipermercados', 'retalho'],
            ['Minipreço', 'Hipermercados', 'retalho'],
            ['Lidl', 'Hipermercados', 'retalho'],
            ['Aldi', 'Hipermercados', 'retalho'],
            ['Intermarché', 'Hipermercados', 'retalho'],
            ['Continente Modelo', 'Hipermercados', 'retalho'],
            ['Super / Hiper Poupança', 'Hipermercados', 'retalho'],
            ['Worten', 'Lojas tecnologia', 'retalho'],
            ['FNAC', 'Lojas tecnologia', 'retalho'],
            ['Radio Popular', 'Lojas tecnologia', 'retalho'],
            ['Staples', 'Lojas tecnologia', 'retalho'],
            ['Darty', 'Lojas tecnologia', 'retalho'],
            ['Makro', 'Cash & Carry', 'retalho'],
            ['Recheio', 'Cash & Carry', 'retalho'],
            ['Mcunha', 'Cash & Carry', 'retalho'],
            ['Marabuto', 'Cash & Carry', 'retalho'],
            ['Malaquias', 'Cash & Carry', 'retalho'],
            ['Grossão', 'Cash & Carry', 'retalho'],
            ['Nortenho', 'Cash & Carry', 'retalho'],
            ['Pereira e Santos', 'Cash & Carry', 'retalho'],
            ['A. Ezequiel', 'Cash & Carry', 'retalho'],
            ['Garcias', 'Cash & Carry', 'retalho'],
            ['Arcol', 'Cash & Carry', 'retalho'],
            ['Farmácia', 'Saúde', 'saude'],
            ['Parafarmácia', 'Saúde', 'saude'],
            ['Posto de combustível', 'Especializado', 'especializado'],
            ['Estação de serviço', 'Especializado', 'especializado'],
            ['Ginásio', 'Especializado', 'especializado'],
            ['Clube', 'Especializado', 'especializado'],
            ['Vending', 'Especializado', 'especializado'],
            ['Banca', 'Outros', 'outros'],
            ['Mobile kiosk', 'Outros', 'outros'],
        ];
        $rootIds = [];
        foreach ($categorySeeds as $seed) {
            [$name, $parentName, $type] = $seed;
            $slug = sanitize_title($name);
            $parentId = null;
            if ($parentName !== null) {
                if (!isset($rootIds[$parentName])) {
                    $rootIds[$parentName] = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table('categories')} WHERE parent_id IS NULL AND slug=%s LIMIT 1", sanitize_title($parentName)));
                }
                $parentId = $rootIds[$parentName] ?: null;
            }
            $existingId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table('categories')} WHERE slug=%s AND " . ($parentId ? 'parent_id=%d' : 'parent_id IS NULL') . " LIMIT 1", ...array_filter([$slug, $parentId], fn($v)=>$v!==null)));
            if ($existingId) {
                $wpdb->update($table('categories'), ['name'=>$name, 'type'=>$type, 'is_active'=>1], ['id'=>$existingId]);
                if ($parentName === null) $rootIds[$name] = $existingId;
            } else {
                $wpdb->insert($table('categories'), ['parent_id'=>$parentId, 'name'=>$name, 'slug'=>$slug, 'type'=>$type, 'is_active'=>1]);
                if ($parentName === null) $rootIds[$name] = (int)$wpdb->insert_id;
            }
        }


    }
}
