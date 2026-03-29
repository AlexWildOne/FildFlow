<?php
namespace RoutesPro\Admin;
use RoutesPro\Forms\Forms as FormsModule;
if (!defined('ABSPATH')) exit;
class FormSubmissions {
    public static function register_hooks() {
        add_action('admin_post_routespro_save_submission', [self::class, 'handle_save']);
        add_action('admin_post_routespro_delete_submission', [self::class, 'handle_delete']);
        add_action('admin_post_routespro_export_submissions', [self::class, 'handle_export']);
    }

    public static function render() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        global $wpdb;

        $filters = [
            'client_id' => absint($_GET['client_id'] ?? 0),
            'project_id' => absint($_GET['project_id'] ?? 0),
            'owner_user_id' => absint($_GET['owner_user_id'] ?? 0),
            'route_id' => absint($_GET['route_id'] ?? 0),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
        ];

        $dataset = self::get_submission_dataset($filters + ['limit' => 300]);
        $rows = $dataset['rows'];
        $dynamic_columns = $dataset['columns'];

        $clients = $wpdb->get_results('SELECT id, name FROM ' . $wpdb->prefix . 'routespro_clients ORDER BY name ASC', ARRAY_A) ?: [];
        $projects = $wpdb->get_results('SELECT id, name FROM ' . $wpdb->prefix . 'routespro_projects ORDER BY name ASC', ARRAY_A) ?: [];
        $owners = $wpdb->get_results('SELECT DISTINCT u.ID AS id, u.display_name AS name FROM ' . $wpdb->prefix . 'routespro_routes r INNER JOIN ' . $wpdb->users . ' u ON u.ID = r.owner_user_id WHERE r.owner_user_id IS NOT NULL ORDER BY u.display_name ASC', ARRAY_A) ?: [];

        echo '<div class="wrap">';
        Branding::render_header('Submissões', 'Agora já com contexto operacional na grelha, filtros por cliente, campanha e owner, e sem menus duplicados no backoffice.');
        if (isset($_GET['saved'])) echo '<div class="notice notice-success"><p>Submissão atualizada.</p></div>';
        if (isset($_GET['deleted'])) echo '<div class="notice notice-success"><p>Submissão apagada.</p></div>';

        self::render_filters($filters, $clients, $projects, $owners);
        self::render_export_actions($filters);

        echo '<div class="routespro-table-scroll" style="overflow:auto;-webkit-overflow-scrolling:touch;margin-top:14px">';
        echo '<table class="widefat striped routespro-wide-table" style="min-width:1200px"><thead><tr><th>ID</th><th>Formulário</th><th>Cliente</th><th>Campanha / Projeto</th><th>Rota</th><th>Paragem</th><th>Owner</th><th>Submetido por</th><th>Data</th><th>Status</th>';
        foreach ($dynamic_columns as $col) echo '<th>' . esc_html($col['label']) . '</th>';
        echo '<th>Ações</th></tr></thead><tbody>';
        if(!$rows){
            echo '<tr><td colspan="' . (11 + count($dynamic_columns)) . '">Ainda não existem submissões para estes filtros.</td></tr>';
        } else {
            foreach($rows as $row){
                $answers = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . FormsModule::table_answers() . ' WHERE submission_id=%d ORDER BY id ASC', (int)$row['id']), ARRAY_A) ?: [];
                $edit = admin_url('admin.php?page=routespro-form-submission-edit&id=' . (int)$row['id']);
                $del = wp_nonce_url(admin_url('admin-post.php?action=routespro_delete_submission&id=' . (int)$row['id']), 'routespro_delete_submission_' . (int)$row['id']);
                echo '<tr>';
                echo '<td>'.(int)$row['id'].'</td>';
                echo '<td>'.esc_html($row['form_title'] ?: ('#'.(int)$row['form_id'])).'</td>';
                echo '<td>'.esc_html($row['client_name'] ?: 'Sem cliente').'</td>';
                echo '<td>'.esc_html($row['project_name'] ?: 'Sem campanha').'</td>';
                echo '<td>' . wp_kses_post(self::render_route_cell($row)) . '</td>';
                echo '<td>' . wp_kses_post(self::render_stop_cell($row)) . '</td>';
                echo '<td>'.esc_html($row['owner_name'] ?: 'Sem owner').'</td>';
                echo '<td>'.esc_html($row['user_name'] ?: ('User #'.(int)$row['user_id'])).'</td>';
                echo '<td>'.esc_html($row['submitted_at']).'</td>';
                echo '<td>'.esc_html($row['status']).'</td>';
                foreach ($dynamic_columns as $col) {
                    $cell = $row['answers'][$col['key']] ?? '';
                    echo '<td style="white-space:nowrap">' . esc_html($cell !== '' ? $cell : '—') . '</td>';
                }
                echo '<td><a class="button button-small" href="'.esc_url($edit).'">Editar</a> <a class="button button-small" href="'.esc_url($del).'" onclick="return confirm(\'Apagar submissão?\')">Apagar</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div>';
    }


    public static function get_submission_dataset(array $filters = []) : array {
        global $wpdb;
        $filters = wp_parse_args($filters, [
            'client_id' => 0,
            'project_id' => 0,
            'owner_user_id' => 0,
            'route_id' => 0,
            'date_from' => '',
            'date_to' => '',
            'limit' => 300,
        ]);

        $sql = 'SELECT s.*, 
            f.title AS form_title,
            submitter.display_name AS user_name,
            c.name AS client_name,
            p.name AS project_name,
            r.id AS joined_route_id,
            r.date AS route_date,
            r.status AS route_status,
            r.owner_user_id AS joined_owner_user_id,
            owner.display_name AS owner_name,
            rs.id AS stop_row_id,
            rs.status AS stop_status,
            loc.name AS location_name
        FROM ' . FormsModule::table_submissions() . ' s
        LEFT JOIN ' . FormsModule::table() . ' f ON f.id = s.form_id
        LEFT JOIN ' . $wpdb->users . ' submitter ON submitter.ID = s.user_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_clients c ON c.id = s.client_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_projects p ON p.id = s.project_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_routes r ON r.id = s.route_id
        LEFT JOIN ' . $wpdb->users . ' owner ON owner.ID = r.owner_user_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_route_stops rs ON rs.id = s.route_stop_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_locations loc ON loc.id = rs.location_id
        WHERE 1=1';
        $params = [];
        if (!empty($filters['client_id'])) { $sql .= ' AND s.client_id = %d'; $params[] = (int) $filters['client_id']; }
        if (!empty($filters['project_id'])) { $sql .= ' AND s.project_id = %d'; $params[] = (int) $filters['project_id']; }
        if (!empty($filters['owner_user_id'])) { $sql .= ' AND r.owner_user_id = %d'; $params[] = (int) $filters['owner_user_id']; }
        if (!empty($filters['route_id'])) { $sql .= ' AND s.route_id = %d'; $params[] = (int) $filters['route_id']; }
        if (!empty($filters['date_from'])) { $sql .= ' AND DATE(s.submitted_at) >= %s'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to'])) { $sql .= ' AND DATE(s.submitted_at) <= %s'; $params[] = $filters['date_to']; }
        $sql .= ' ORDER BY s.submitted_at DESC, s.id DESC';
        if (!empty($filters['limit'])) { $sql .= ' LIMIT ' . max(1, (int) $filters['limit']); }
        if ($params) $sql = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $columns = [];
        $seen = [];
        foreach ($rows as &$row) {
            $answers = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . FormsModule::table_answers() . ' WHERE submission_id=%d ORDER BY id ASC', (int)$row['id']), ARRAY_A) ?: [];
            $row['answers'] = [];
            foreach ($answers as $a) {
                $key = sanitize_key($a['question_key'] ?: ('q_' . $a['id']));
                $label = (string) ($a['question_label'] ?: $a['question_key']);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $columns[] = ['key' => $key, 'label' => $label];
                }
                $row['answers'][$key] = self::answer_to_string($a);
            }
        }
        unset($row);
        return ['rows' => $rows, 'columns' => $columns];
    }

    private static function render_export_actions(array $filters) {
        $base = admin_url('admin-post.php?action=routespro_export_submissions');
        $query = [];
        foreach (['client_id','project_id','owner_user_id','route_id','date_from','date_to'] as $k) {
            if (!empty($filters[$k])) $query[$k] = $filters[$k];
        }
        $csv = wp_nonce_url(add_query_arg($query + ['format' => 'csv'], $base), 'routespro_export_submissions');
        $xls = wp_nonce_url(add_query_arg($query + ['format' => 'xls'], $base), 'routespro_export_submissions');
        $pdf = wp_nonce_url(add_query_arg($query + ['format' => 'pdf'], $base), 'routespro_export_submissions');
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px">';
        echo '<a class="button button-primary" href="' . esc_url($csv) . '">Exportar CSV</a>';
        echo '<a class="button" href="' . esc_url($xls) . '">Exportar Excel</a>';
        echo '<a class="button" href="' . esc_url($pdf) . '">Exportar PDF</a>';
        echo '<span style="color:#64748b">Exporta o mesmo dataset visível na grelha, incluindo contexto da rota, colunas dinâmicas das perguntas e, no PDF, um relatório com cabeçalho TWT e filtros aplicados.</span>';
        echo '</div>';
    }

    public static function handle_export() {
        if (!(current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front())) wp_die('Sem permissões.');
        $nonce_ok = false;
        if (!empty($_REQUEST['_wpnonce'])) {
            $nonce_ok = wp_verify_nonce((string) $_REQUEST['_wpnonce'], 'routespro_export_submissions');
        }
        if (!$nonce_ok && is_admin()) {
            check_admin_referer('routespro_export_submissions');
        }
        $filters = [
            'client_id' => absint($_GET['client_id'] ?? 0),
            'project_id' => absint($_GET['project_id'] ?? 0),
            'owner_user_id' => absint($_GET['owner_user_id'] ?? 0),
            'route_id' => absint($_GET['route_id'] ?? 0),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
            'limit' => 2000,
        ];
        $format = sanitize_key($_GET['format'] ?? 'csv');
        $dataset = self::get_submission_dataset($filters);
        $headers = ['ID','Formulário','Cliente','Campanha / Projeto','Rota','Paragem','Owner','Submetido por','Data','Status'];
        foreach ($dataset['columns'] as $col) $headers[] = $col['label'];
        $table_rows = [];
        foreach ($dataset['rows'] as $row) {
            $line = [
                (int) $row['id'],
                (string) ($row['form_title'] ?: ('#' . (int) $row['form_id'])),
                (string) ($row['client_name'] ?: ''),
                (string) ($row['project_name'] ?: ''),
                wp_strip_all_tags(self::render_route_cell($row)),
                wp_strip_all_tags(self::render_stop_cell($row)),
                (string) ($row['owner_name'] ?: ''),
                (string) ($row['user_name'] ?: ('User #' . (int) $row['user_id'])),
                (string) ($row['submitted_at'] ?: ''),
                (string) ($row['status'] ?: ''),
            ];
            foreach ($dataset['columns'] as $col) $line[] = (string) ($row['answers'][$col['key']] ?? '');
            $table_rows[] = $line;
        }
        $filename = 'routespro-submissoes-' . gmdate('Ymd-His');
        if ($format === 'xls') {
            self::output_excel_xml($filename . '.xls', $headers, $table_rows);
        }
        if ($format === 'pdf') {
            self::output_pdf_report($filename . '.pdf', $filters, $dataset);
        }
        self::output_csv($filename . '.csv', $headers, $table_rows);
    }

    private static function output_csv(string $filename, array $headers, array $rows) {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, $headers);
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }

    private static function output_excel_xml(string $filename, array $headers, array $rows) {
        nocache_headers();
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Submissoes"><Table>';
        echo '<Row>';
        foreach ($headers as $cell) echo '<Cell><Data ss:Type="String">' . esc_html($cell) . '</Data></Cell>';
        echo '</Row>';
        foreach ($rows as $row) {
            echo '<Row>';
            foreach ($row as $cell) echo '<Cell><Data ss:Type="String">' . esc_html((string) $cell) . '</Data></Cell>';
            echo '</Row>';
        }
        echo '</Table></Worksheet></Workbook>';
        exit;
    }


    private static function output_pdf_report(string $filename, array $filters, array $dataset) {
        $filter_lines = self::build_filter_lines($filters);
        $body_lines = [];
        $body_lines[] = 'Relatorio de submissoes FieldFlow';
        $body_lines[] = 'Gerado em: ' . wp_date('d/m/Y H:i');
        $body_lines[] = '';
        $body_lines[] = 'Filtros aplicados:';
        foreach ($filter_lines as $line) $body_lines[] = '- ' . $line;
        $body_lines[] = '';
        $body_lines[] = 'Total de submissoes: ' . count($dataset['rows']);
        $body_lines[] = '';
        foreach ($dataset['rows'] as $index => $row) {
            $body_lines[] = 'Submissao #' . (int) $row['id'] . ' | ' . ($row['submitted_at'] ?: 'Sem data');
            $body_lines[] = 'Formulario: ' . ($row['form_title'] ?: ('#' . (int) $row['form_id']));
            $body_lines[] = 'Cliente: ' . ($row['client_name'] ?: 'Sem cliente');
            $body_lines[] = 'Campanha / Projeto: ' . ($row['project_name'] ?: 'Sem campanha');
            $body_lines[] = 'Rota: ' . trim((string) preg_replace('/
+/', ' ', wp_strip_all_tags(self::render_route_cell($row))));
            $body_lines[] = 'Paragem: ' . trim((string) preg_replace('/
+/', ' ', wp_strip_all_tags(self::render_stop_cell($row))));
            $body_lines[] = 'Owner: ' . ($row['owner_name'] ?: 'Sem owner');
            $body_lines[] = 'Submetido por: ' . ($row['user_name'] ?: ('User #' . (int) $row['user_id']));
            $body_lines[] = 'Status: ' . ($row['status'] ?: '');
            if (!empty($dataset['columns'])) {
                $body_lines[] = 'Respostas:';
                foreach ($dataset['columns'] as $col) {
                    $value = trim((string) ($row['answers'][$col['key']] ?? ''));
                    if ($value === '') $value = '--';
                    $body_lines[] = '* ' . $col['label'] . ': ' . $value;
                }
            }
            if ($index < count($dataset['rows']) - 1) {
                $body_lines[] = str_repeat('-', 90);
                $body_lines[] = '';
            }
        }

        $pdf = self::build_simple_pdf($body_lines, [
            'title' => 'Relatorio de submissoes',
            'subtitle' => 'TWT | FieldFlow',
            'logo_path' => ROUTESPRO_PATH . 'assets/logo-twt.jpg',
        ]);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    private static function build_filter_lines(array $filters): array {
        global $wpdb;
        $lines = [];
        if (!empty($filters['client_id'])) {
            $name = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . $wpdb->prefix . 'routespro_clients WHERE id=%d', (int) $filters['client_id']));
            $lines[] = 'Cliente: ' . ($name ?: ('#' . (int) $filters['client_id']));
        }
        if (!empty($filters['project_id'])) {
            $name = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . $wpdb->prefix . 'routespro_projects WHERE id=%d', (int) $filters['project_id']));
            $lines[] = 'Campanha / Projeto: ' . ($name ?: ('#' . (int) $filters['project_id']));
        }
        if (!empty($filters['owner_user_id'])) {
            $user = get_userdata((int) $filters['owner_user_id']);
            $lines[] = 'Owner: ' . ($user ? $user->display_name : ('#' . (int) $filters['owner_user_id']));
        }
        if (!empty($filters['route_id'])) {
            $route = $wpdb->get_row($wpdb->prepare('SELECT id, date, status FROM ' . $wpdb->prefix . 'routespro_routes WHERE id=%d', (int) $filters['route_id']), ARRAY_A);
            $lines[] = 'Rota: ' . ($route ? ('#' . (int) $route['id'] . ' | ' . ($route['date'] ?: 'Sem data') . ' | ' . ($route['status'] ?: '')) : ('#' . (int) $filters['route_id']));
        }
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $lines[] = 'Periodo: ' . (!empty($filters['date_from']) ? $filters['date_from'] : '...') . ' ate ' . (!empty($filters['date_to']) ? $filters['date_to'] : '...');
        }
        if (empty($lines)) $lines[] = 'Sem filtros especificos, relatorio global dentro do ambito visivel.';
        return $lines;
    }

    private static function build_simple_pdf(array $lines, array $options = []): string {
        $title = (string) ($options['title'] ?? 'Relatorio');
        $subtitle = (string) ($options['subtitle'] ?? '');
        $logo_path = (string) ($options['logo_path'] ?? '');
        $max_chars = 105;
        $wrapped = [];
        foreach ($lines as $line) {
            $line = wp_strip_all_tags((string) $line);
            $line = preg_replace('/
|	/', ' ', $line);
            if ($line === '') {
                $wrapped[] = '';
                continue;
            }
            foreach (preg_split('/
/', wordwrap($line, $max_chars, "\n", true)) as $chunk) {
                $wrapped[] = $chunk;
            }
        }

        $page_w = 841.89;
        $page_h = 595.28;
        $line_h = 13;
        $pages = [];
        $current = [];
        $y = 510;
        foreach ($wrapped as $line) {
            if ($y < 46) {
                $pages[] = $current;
                $current = [];
                $y = 545;
            }
            $current[] = [$line, $y];
            $y -= $line_h;
        }
        if ($current || empty($pages)) $pages[] = $current;

        $objects = [];
        $add = function ($content) use (&$objects) {
            $objects[] = $content;
            return count($objects);
        };

        $font_regular = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $font_bold = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>');

        $image_obj = 0;
        $image_w = 0;
        $image_h = 0;
        if ($logo_path && is_readable($logo_path)) {
            $img_data = file_get_contents($logo_path);
            $img_info = @getimagesize($logo_path);
            if ($img_data !== false && !empty($img_info[0]) && !empty($img_info[1])) {
                $image_w = (int) $img_info[0];
                $image_h = (int) $img_info[1];
                $image_obj = $add("<< /Type /XObject /Subtype /Image /Width {$image_w} /Height {$image_h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($img_data) . " >>\nstream\n" . $img_data . "\nendstream");
            }
        }

        $content_ids = [];
        $page_ids = [];
        foreach ($pages as $page_index => $page_lines) {
            $stream = '';
            if ($page_index === 0 && $image_obj) {
                $draw_w = 135;
                $draw_h = max(24, ($image_h / max(1, $image_w)) * $draw_w);
                $stream .= 'q ' . number_format($draw_w, 2, '.', '') . ' 0 0 ' . number_format($draw_h, 2, '.', '') . ' 40 530 cm /Im1 Do Q' . "\n";
            }
            $stream .= 'BT /F2 17 Tf 190 548 Td (' . self::pdf_escape($title) . ') Tj ET' . "\n";
            if ($subtitle !== '') $stream .= 'BT /F1 10 Tf 190 532 Td (' . self::pdf_escape($subtitle) . ') Tj ET' . "\n";
            foreach ($page_lines as $line_data) {
                [$line, $yy] = $line_data;
                $font = (strpos($line, 'Submissao #') === 0 || $line === 'Filtros aplicados:' || $line === 'Respostas:' || strpos($line, 'Relatorio de submissoes') === 0) ? '/F2 10 Tf' : '/F1 9 Tf';
                $stream .= 'BT ' . $font . ' 40 ' . number_format($yy, 2, '.', '') . ' Td (' . self::pdf_escape($line) . ') Tj ET' . "\n";
            }
            $content_ids[] = $add("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream");
            $page_ids[] = 0;
        }

        $pages_root_hint = count($objects) + count($pages) + 1;
        foreach ($pages as $i => $page_lines) {
            $resources = '<< /Font << /F1 ' . $font_regular . ' 0 R /F2 ' . $font_bold . ' 0 R >>';
            if ($image_obj) $resources .= ' /XObject << /Im1 ' . $image_obj . ' 0 R >>';
            $resources .= ' >>';
            $page_ids[$i] = $add('<< /Type /Page /Parent ' . $pages_root_hint . ' 0 R /MediaBox [0 0 ' . $page_w . ' ' . $page_h . '] /Resources ' . $resources . ' /Contents ' . $content_ids[$i] . ' 0 R >>');
        }
        $kids = implode(' ', array_map(static function ($id) { return $id . ' 0 R'; }, $page_ids));
        $pages_id = $add('<< /Type /Pages /Kids [ ' . $kids . ' ] /Count ' . count($page_ids) . ' >>');
        $catalog_id = $add('<< /Type /Catalog /Pages ' . $pages_id . ' 0 R >>');

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $idx => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($idx + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root ' . $catalog_id . ' 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private static function pdf_escape(string $text): string {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = wp_strip_all_tags($text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) $text = $converted;
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    public static function render_edit() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        global $wpdb;
        $id = absint($_GET['id'] ?? 0);
        $submission = $id ? $wpdb->get_row($wpdb->prepare('SELECT s.*, c.name AS client_name, p.name AS project_name, r.owner_user_id, r.date AS route_date, r.status AS route_status, rs.status AS stop_status, loc.name AS location_name, owner.display_name AS owner_name FROM ' . FormsModule::table_submissions() . ' s LEFT JOIN ' . $wpdb->prefix . 'routespro_clients c ON c.id=s.client_id LEFT JOIN ' . $wpdb->prefix . 'routespro_projects p ON p.id=s.project_id LEFT JOIN ' . $wpdb->prefix . 'routespro_routes r ON r.id=s.route_id LEFT JOIN ' . $wpdb->users . ' owner ON owner.ID=r.owner_user_id LEFT JOIN ' . $wpdb->prefix . 'routespro_route_stops rs ON rs.id=s.route_stop_id LEFT JOIN ' . $wpdb->prefix . 'routespro_locations loc ON loc.id=rs.location_id WHERE s.id=%d', $id), ARRAY_A) : null;
        if (!$submission) wp_die('Submissão não encontrada.');
        $form = FormsModule::get_form((int)$submission['form_id']);
        $schema = FormsModule::decode_schema($form['schema_json'] ?? '');
        $answers = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . FormsModule::table_answers() . ' WHERE submission_id=%d ORDER BY id ASC', $id), ARRAY_A) ?: [];
        $answers_by_key = [];
        foreach ($answers as $a) $answers_by_key[$a['question_key']] = $a;
        echo '<div class="wrap">';
        Branding::render_header('Editar submissão', 'Podes corrigir respostas, ajustar o estado e rever também o contexto operacional da rota.');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="routespro_save_submission">';
        echo '<input type="hidden" name="id" value="' . (int)$id . '">';
        wp_nonce_field('routespro_save_submission_' . $id, 'routespro_save_submission_nonce');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">ID</th><td>#' . (int)$submission['id'] . '</td></tr>';
        echo '<tr><th scope="row">Formulário</th><td>' . esc_html($form['title'] ?? ('#' . (int)$submission['form_id'])) . '</td></tr>';
        echo '<tr><th scope="row">Cliente</th><td>' . esc_html($submission['client_name'] ?: 'Sem cliente') . '</td></tr>';
        echo '<tr><th scope="row">Campanha / Projeto</th><td>' . esc_html($submission['project_name'] ?: 'Sem campanha') . '</td></tr>';
        echo '<tr><th scope="row">Rota</th><td>' . wp_kses_post(self::render_route_cell($submission)) . '</td></tr>';
        echo '<tr><th scope="row">Paragem</th><td>' . wp_kses_post(self::render_stop_cell($submission)) . '</td></tr>';
        echo '<tr><th scope="row">Owner</th><td>' . esc_html($submission['owner_name'] ?: 'Sem owner') . '</td></tr>';
        echo '<tr><th scope="row"><label for="routespro_submission_status">Status</label></th><td><select id="routespro_submission_status" name="status">';
        foreach (['submitted'=>'Submetida','reviewed'=>'Revista','approved'=>'Aprovada','rejected'=>'Rejeitada','draft'=>'Rascunho'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($submission['status'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '</tbody></table>';
        echo '<h2 style="margin-top:24px">Respostas</h2>';
        if (!empty($schema['questions'])) {
            echo '<table class="form-table" role="presentation"><tbody>';
            foreach ($schema['questions'] as $question) {
                if (!is_array($question)) continue;
                $key = sanitize_key($question['key'] ?? '');
                if (!$key) continue;
                $answer = $answers_by_key[$key] ?? null;
                $type = sanitize_key($question['type'] ?? 'text');
                $label = sanitize_text_field($question['label'] ?? $key);
                echo '<tr><th scope="row"><label for="routespro_answer_' . esc_attr($key) . '">' . esc_html($label) . '</label><div style="font-weight:400;color:#64748b;margin-top:4px">' . esc_html($key) . '</div></th><td>';
                self::render_answer_input($key, $type, $answer, $question);
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>O formulário já não tem schema disponível. Vais ver apenas as respostas atuais.</p>';
            echo '<table class="form-table" role="presentation"><tbody>';
            foreach ($answers as $answer) {
                $key = sanitize_key($answer['question_key'] ?? '');
                $label = sanitize_text_field($answer['question_label'] ?: $key);
                echo '<tr><th scope="row"><label for="routespro_answer_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
                self::render_answer_input($key, 'text', $answer, []);
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        submit_button('Guardar submissão');
        echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=routespro-form-submissions')) . '">Voltar</a>';
        echo '</form></div>';
    }

    public static function handle_save() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('routespro_save_submission_' . $id, 'routespro_save_submission_nonce');
        global $wpdb;
        $submission = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . FormsModule::table_submissions() . ' WHERE id=%d', $id), ARRAY_A) : null;
        if (!$submission) wp_die('Submissão não encontrada.');
        $status = sanitize_key($_POST['status'] ?? 'submitted');
        $allowed_status = ['submitted','reviewed','approved','rejected','draft'];
        if (!in_array($status, $allowed_status, true)) $status = 'submitted';
        $wpdb->update(FormsModule::table_submissions(), ['status' => $status], ['id' => $id], ['%s'], ['%d']);
        $form = FormsModule::get_form((int)$submission['form_id']);
        $schema = FormsModule::decode_schema($form['schema_json'] ?? '');
        $questions = !empty($schema['questions']) && is_array($schema['questions']) ? $schema['questions'] : [];
        $known_keys = [];
        foreach ($questions as $question) {
            if (!is_array($question)) continue;
            $key = sanitize_key($question['key'] ?? '');
            if (!$key) continue;
            $known_keys[] = $key;
            $type = sanitize_key($question['type'] ?? 'text');
            $label = sanitize_text_field($question['label'] ?? $key);
            $value = self::extract_posted_answer($key, $type);
            self::upsert_answer($id, $key, $label, $type, $value);
        }
        if (!$known_keys) {
            foreach ((array)($_POST['answers'] ?? []) as $key => $raw) {
                $key = sanitize_key($key);
                self::upsert_answer($id, $key, $key, 'text', is_array($raw) ? $raw : wp_unslash($raw));
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=routespro-form-submissions&saved=1'));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('routespro_delete_submission_' . $id);
        global $wpdb;
        if ($id) {
            $wpdb->delete(FormsModule::table_answers(), ['submission_id' => $id], ['%d']);
            $wpdb->delete(FormsModule::table_submissions(), ['id' => $id], ['%d']);
        }
        wp_safe_redirect(admin_url('admin.php?page=routespro-form-submissions&deleted=1'));
        exit;
    }

    private static function render_filters(array $filters, array $clients, array $projects, array $owners) {
        $url = admin_url('admin.php');
        echo '<form method="get" action="' . esc_url($url) . '" style="margin:16px 0 18px;padding:14px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:12px">';
        echo '<input type="hidden" name="page" value="routespro-form-submissions">';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">';
        echo '<p style="margin:0"><label for="routespro_filter_client"><strong>Cliente</strong></label><br><select id="routespro_filter_client" name="client_id"><option value="0">Todos</option>';
        foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($filters['client_id'], (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
        echo '</select></p>';
        echo '<p style="margin:0"><label for="routespro_filter_project"><strong>Campanha / Projeto</strong></label><br><select id="routespro_filter_project" name="project_id"><option value="0">Todas</option>';
        foreach ($projects as $project) echo '<option value="' . (int)$project['id'] . '"' . selected($filters['project_id'], (int)$project['id'], false) . '>' . esc_html($project['name']) . '</option>';
        echo '</select></p>';
        echo '<p style="margin:0"><label for="routespro_filter_owner"><strong>Owner</strong></label><br><select id="routespro_filter_owner" name="owner_user_id"><option value="0">Todos</option>';
        foreach ($owners as $owner) echo '<option value="' . (int)$owner['id'] . '"' . selected($filters['owner_user_id'], (int)$owner['id'], false) . '>' . esc_html($owner['name']) . '</option>';
        echo '</select></p>';
        echo '<p style="margin:0"><label for="routespro_filter_date_from"><strong>De</strong></label><br><input type="date" id="routespro_filter_date_from" name="date_from" value="' . esc_attr($filters['date_from'] ?? '') . '"></p>';
        echo '<p style="margin:0"><label for="routespro_filter_date_to"><strong>Até</strong></label><br><input type="date" id="routespro_filter_date_to" name="date_to" value="' . esc_attr($filters['date_to'] ?? '') . '"></p>';
        echo '<p style="margin:0"><button class="button button-primary">Filtrar</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=routespro-form-submissions')) . '">Limpar</a></p>';
        echo '</div>';
        echo '</form>';
    }

    private static function render_route_cell(array $row): string {
        $route_id = (int)($row['route_id'] ?? $row['joined_route_id'] ?? 0);
        if (!$route_id) return 'Sem rota';
        $parts = ['#' . $route_id];
        if (!empty($row['route_date'])) $parts[] = esc_html($row['route_date']);
        if (!empty($row['route_status'])) $parts[] = esc_html($row['route_status']);
        return implode('<br>', $parts);
    }

    private static function render_stop_cell(array $row): string {
        $stop_id = (int)($row['route_stop_id'] ?? $row['stop_row_id'] ?? 0);
        if (!$stop_id) return 'Sem paragem';
        $parts = ['#' . $stop_id];
        if (!empty($row['location_name'])) $parts[] = esc_html($row['location_name']);
        if (!empty($row['stop_status'])) $parts[] = esc_html($row['stop_status']);
        return implode('<br>', $parts);
    }

    private static function render_answer_input(string $key, string $type, ?array $answer, array $question) {
        $field_name = 'answers[' . $key . ']';
        $id = 'routespro_answer_' . $key;
        $value = self::answer_raw_value($answer, $type);
        $options = [];
        if (!empty($question['options']) && is_array($question['options'])) $options = array_values(array_filter(array_map('sanitize_text_field', $question['options'])));
        if (in_array($type, ['textarea'], true)) {
            echo '<textarea class="large-text" rows="5" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '">' . esc_textarea(is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value) . '</textarea>';
            return;
        }
        if (in_array($type, ['select','radio'], true) && $options) {
            echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '"><option value="">Selecione</option>';
            foreach ($options as $option) echo '<option value="' . esc_attr($option) . '"' . selected((string)$value, (string)$option, false) . '>' . esc_html($option) . '</option>';
            echo '</select>';
            return;
        }
        if ($type === 'checkbox') {
            echo '<label><input type="hidden" name="' . esc_attr($field_name) . '" value="0"><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="1"' . checked(!empty($value), true, false) . '> Marcado</label>';
            return;
        }
        if (in_array($type, ['number','currency','percent'], true)) {
            echo '<input type="number" step="0.01" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="' . esc_attr((string)$value) . '">';
            return;
        }
        if (in_array($type, ['date','time'], true)) {
            echo '<input type="' . esc_attr($type) . '" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="' . esc_attr((string)$value) . '">';
            return;
        }
        if (in_array($type, ['image_upload','file_upload'], true)) {
            if (!empty($value)) echo '<p><a href="' . esc_url((string)$value) . '" target="_blank" rel="noopener">Ver ficheiro atual</a></p>';
            echo '<input type="url" class="large-text" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="' . esc_attr((string)$value) . '" placeholder="https://">';
            return;
        }
        echo '<input type="text" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="' . esc_attr(is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value) . '">';
    }

    private static function extract_posted_answer(string $key, string $type) {
        $raw = $_POST['answers'][$key] ?? null;
        if ($type === 'checkbox') return !empty($raw) ? 1 : 0;
        if (is_array($raw)) return array_map('sanitize_text_field', wp_unslash($raw));
        $raw = is_string($raw) ? wp_unslash($raw) : $raw;
        switch ($type) {
            case 'number':
            case 'currency':
            case 'percent':
                return ($raw === '' || $raw === null) ? '' : (float) str_replace(',', '.', (string) $raw);
            case 'textarea':
                return sanitize_textarea_field((string) $raw);
            case 'image_upload':
            case 'file_upload':
                return esc_url_raw((string) $raw);
            default:
                return sanitize_text_field((string) $raw);
        }
    }

    private static function upsert_answer(int $submission_id, string $key, string $label, string $type, $value) {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . FormsModule::table_answers() . ' WHERE submission_id=%d AND question_key=%s LIMIT 1', $submission_id, $key), ARRAY_A);
        $stored = self::prepare_answer_storage($value, $type);
        $data = [
            'question_label' => $label,
            'value_text' => $stored['text'],
            'value_number' => $stored['number'],
            'value_json' => $stored['json'],
        ];
        $formats = ['%s','%s','%f','%s'];
        if ($existing) {
            $wpdb->update(FormsModule::table_answers(), $data, ['id' => (int)$existing['id']], $formats, ['%d']);
        } else {
            $wpdb->insert(FormsModule::table_answers(), [
                'submission_id' => $submission_id,
                'question_key' => $key,
                'question_label' => $label,
                'value_text' => $stored['text'],
                'value_number' => $stored['number'],
                'value_json' => $stored['json'],
                'created_at' => current_time('mysql'),
            ], ['%d','%s','%s','%s','%f','%s','%s']);
        }
    }

    private static function prepare_answer_storage($value, string $type): array {
        $text = null; $number = null; $json = null;
        if (is_array($value)) {
            $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            $text = $json;
        } elseif (in_array($type, ['number','currency','percent'], true) && $value !== '') {
            $number = (float) $value;
            $text = (string) $value;
        } elseif ($type === 'checkbox') {
            $number = $value ? 1 : 0;
            $text = $value ? '1' : '0';
        } else {
            $text = is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return ['text' => $text, 'number' => $number, 'json' => $json];
    }

    private static function answer_raw_value(?array $answer, string $type) {
        if (!$answer) return '';
        if (!empty($answer['value_json'])) {
            $decoded = json_decode($answer['value_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
        if (in_array($type, ['number','currency','percent'], true) && $answer['value_number'] !== null) return $answer['value_number'];
        if ($type === 'checkbox') return !empty($answer['value_number']) || $answer['value_text'] === '1';
        return $answer['value_text'] ?? '';
    }

    private static function answer_to_string(array $answer): string {
        if (!empty($answer['value_json'])) {
            $decoded = json_decode($answer['value_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded)) return implode(', ', array_map('strval', $decoded));
                return (string) $decoded;
            }
            return (string) $answer['value_json'];
        }
        if ($answer['value_number'] !== null && $answer['value_text'] === null) return (string) $answer['value_number'];
        return (string) ($answer['value_text'] ?? '');
    }
}
