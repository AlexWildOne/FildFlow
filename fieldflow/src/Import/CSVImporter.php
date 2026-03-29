<?php
namespace RoutesPro\Import;

if (!defined('ABSPATH')) { exit; }

class CSVImporter {

    public static function handle_upload() {
        if (!current_user_can('routespro_manage')) { echo '<p>Sem permissões.</p>'; return; }

        if (empty($_FILES['routespro_csv']['tmp_name'])) { echo '<p>Ficheiro inválido.</p>'; return; }
        $tmp = $_FILES['routespro_csv']['tmp_name'];

        // ---- Helpers ----
        $normalize_header = function($h){
            $h = trim($h);
            // Remover BOM (caso exista no primeiro cabeçalho)
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            $h = mb_strtolower($h, 'UTF-8');
            $h = str_replace([' ', '-'], '_', $h);
            return $h;
        };

        // Aliases PT/EN comuns
        $alias = [
            'nome'               => 'name',
            'morada'             => 'address',
            'endereco'           => 'address',
            'latitude'           => 'lat',
            'longitude'          => 'lng',
            'janela_inicio'      => 'window_start',
            'janela_fim'         => 'window_end',
            'inicio_janela'      => 'window_start',
            'fim_janela'         => 'window_end',
            'tempo_servico_min'  => 'service_time_min',
            'tempo_de_servico'   => 'service_time_min',
            'ref_externa'        => 'external_ref',
            'referencia'         => 'external_ref',
            'projeto_id'         => 'project_id',
            'projeto'            => 'project_id',
            'cliente_id'         => 'client_id',
            'tags'               => 'tags',
            'windowstart'        => 'window_start',
            'windowend'          => 'window_end',
            'service_time'       => 'service_time_min',
        ];

        // Autodeteção de delimitador (; , \t |)
        $detect_delimiter = function($file) {
            $delims = [';', ',', "\t", '|'];
            $sample = file_get_contents($file, false, null, 0, 4096);
            if ($sample === false) return ';';
            $best = ';'; $bestCount = -1;
            foreach ($delims as $d) {
                $c = substr_count($sample, $d);
                if ($c > $bestCount) { $bestCount = $c; $best = $d; }
            }
            return $best ?: ';';
        };

        // Converte "12,34" => "12.34"
        $norm_num = function($v){
            if ($v === '' || $v === null) return null;
            $v = str_replace([' ', "\xC2\xA0"], '', (string)$v);
            $v = str_replace(',', '.', $v);
            if (!is_numeric($v)) return null;
            return (float)$v;
        };

        // Valida hora "HH:MM" (ou HH:MM:SS → corta)
        $norm_hhmm = function($v){
            $v = trim((string)$v);
            if ($v === '') return null;
            // Aceita HH:MM ou HH:MM:SS
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v, $m)) {
                return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            }
            return null;
        };

        // Inteiro seguro (ou null)
        $to_int_or_null = function($v){
            $v = trim((string)$v);
            if ($v === '') return null;
            return (int)$v;
        };

        // ---- Ler CSV ----
        $delim = $detect_delimiter($tmp);
        $fh = fopen($tmp, 'r');
        if (!$fh) { echo '<p>Erro ao abrir ficheiro.</p>'; return; }

        $rawHeader = fgetcsv($fh, 0, $delim);
        if (!$rawHeader) { fclose($fh); echo '<p>CSV sem cabeçalho.</p>'; return; }

        $header = array_map($normalize_header, $rawHeader);
        // Aplicar aliases
        foreach ($header as &$h) {
            if (isset($alias[$h])) $h = $alias[$h];
        }
        unset($h);

        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            if (!$r) continue;
            // Ignorar linhas totalmente vazias
            if (count(array_filter($r, function($x){ return trim((string)$x) !== ''; })) === 0) continue;

            // Se a linha tiver menos colunas, completa com nulls
            if (count($r) < count($header)) {
                $r = array_pad($r, count($header), null);
            }
            $assoc = array_combine($header, $r);
            if ($assoc === false) continue;

            $rows[] = $assoc;
        }
        fclose($fh);

        // ---- Pré-visualização ----
        echo '<h3>Pré-visualização (primeiras 10 linhas)</h3><table class="widefat"><thead><tr>';
        foreach ($header as $h) echo '<th>' . esc_html($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach (array_slice($rows, 0, 10) as $row) {
            echo '<tr>';
            foreach ($header as $h) echo '<td>' . esc_html((string)($row[$h] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // ---- Import ----
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';

        $client_id_form  = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $project_id_form = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

        if (!$client_id_form) {
            echo '<p style="color:#c00"><strong>Erro:</strong> Cliente ID é obrigatório no formulário.</p>';
            return;
        }

        // Performance / Resiliência
        @set_time_limit(0);
        $wpdb->query('START TRANSACTION');

        $inserted = 0; $updated = 0; $skipped = 0;
        $errors = [];

        // Verifica se existe índice/unique por external_ref — se sim, podemos atualizar em vez de duplicar
        // Vamos usar uma heurística: se a coluna external_ref vier preenchida, tentamos "upsert".
        foreach ($rows as $i => $row) {
            try {
                // Mapeamentos e saneamento
                $name    = sanitize_text_field($row['name']    ?? ($row['address'] ?? ''));
                $address = sanitize_textarea_field($row['address'] ?? '');
                $lat     = $norm_num($row['lat']  ?? null);
                $lng     = $norm_num($row['lng']  ?? null);

                $window_start = $norm_hhmm($row['window_start'] ?? null);
                $window_end   = $norm_hhmm($row['window_end']   ?? null);

                $service_time_min = isset($row['service_time_min']) ? (int)$row['service_time_min'] : 0;
                if ($service_time_min < 0) $service_time_min = 0;

                $tags        = sanitize_text_field($row['tags']        ?? '');
                $externalRef = sanitize_text_field($row['external_ref'] ?? '');

                // Permitir project_id via CSV sobrepor o do formulário
                $project_id_csv  = $to_int_or_null($row['project_id'] ?? null);
                $project_id_use  = ($project_id_csv !== null) ? $project_id_csv : ($project_id_form ?: null);

                // Regras básicas de aceitação:
                // Pelo menos 'name' OU 'address' preenchido
                if ($name === '' && $address === '') {
                    $skipped++; continue;
                }
                // Se vier lat/lng, ambas devem ser numéricas
                if (($row['lat'] ?? '') !== '' || ($row['lng'] ?? '') !== '') {
                    if ($lat === null || $lng === null) {
                        $skipped++; continue;
                    }
                }

                // Tentar update se external_ref coincidir (no mesmo cliente & projeto)
                $did_update = false;
                if ($externalRef !== '') {
                    $whereParts = ['client_id = %d', 'external_ref = %s'];
                    $whereArgs  = [$client_id_form, $externalRef];
                    if ($project_id_use !== null) {
                        $whereParts[] = 'project_id ' . ($project_id_use === null ? 'IS NULL' : '= %d');
                        if ($project_id_use !== null) $whereArgs[] = $project_id_use;
                    }

                    $existing = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT id FROM {$px}locations WHERE ".implode(' AND ', $whereParts)." LIMIT 1",
                            ...$whereArgs
                        ),
                        ARRAY_A
                    );

                    if ($existing && !empty($existing['id'])) {
                        $upd = [
                            'name'              => $name,
                            'address'           => $address,
                            'lat'               => $lat,
                            'lng'               => $lng,
                            'window_start'      => $window_start,
                            'window_end'        => $window_end,
                            'service_time_min'  => $service_time_min,
                            'tags'              => $tags,
                        ];
                        // Nota: não alteramos client_id/project_id em updates por segurança
                        $ok = $wpdb->update($px.'locations', $upd, ['id' => (int)$existing['id']], [
                            '%s','%s','%f','%f','%s','%s','%d','%s'
                        ], ['%d']);
                        if ($ok === false) { throw new \RuntimeException($wpdb->last_error ?: 'DB update falhou'); }
                        $updated++; $did_update = true;
                    }
                }

                if (!$did_update) {
                    $ins = [
                        'client_id'        => $client_id_form,
                        'project_id'       => $project_id_use,
                        'name'             => $name,
                        'address'          => $address,
                        'lat'              => $lat,
                        'lng'              => $lng,
                        'window_start'     => $window_start,
                        'window_end'       => $window_end,
                        'service_time_min' => $service_time_min,
                        'tags'             => $tags,
                        'external_ref'     => $externalRef,
                    ];

                    // Formatos — permitir NULL para project_id/lat/lng/window_*
                    $fmts = ['%d', null, '%s', '%s', null, null, null, null, '%d', '%s', '%s'];
                    // Ajusta formatos conforme valores
                    $fmts[1] = ($project_id_use === null) ? null : '%d';
                    $fmts[4] = ($lat === null)          ? null : '%f';
                    $fmts[5] = ($lng === null)          ? null : '%f';
                    $fmts[6] = ($window_start === null) ? null : '%s';
                    $fmts[7] = ($window_end === null)   ? null : '%s';

                    $result = \RoutesPro\Services\LocationDeduplicator::upsert($ins, 0, true);
                    if (!empty($result['existing'])) {
                        $updated++;
                    } else {
                        $inserted++;
                    }
                }

            } catch (\Throwable $e) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Linha '.($i+2).': '.$e->getMessage(); // +2: conta cabeçalho + base-1
                }
                continue;
            }
        }

        $wpdb->query('COMMIT');

        echo '<p><strong>Resumo:</strong></p>';
        echo '<ul style="list-style:disc;padding-left:20px">';
        echo '<li>Inseridas: <strong>'.intval($inserted).'</strong></li>';
        echo '<li>Atualizadas (por external_ref): <strong>'.intval($updated).'</strong></li>';
        echo '<li>Ignoradas/Com erro: <strong>'.intval($skipped).'</strong></li>';
        echo '</ul>';

        if ($errors) {
            echo '<div class="notice notice-warning" style="padding:8px;margin-top:10px"><p><strong>Exemplos de erros (máx. 10):</strong></p><ul style="margin:0;padding-left:18px">';
            foreach ($errors as $e) echo '<li>'.esc_html($e).'</li>';
            echo '</ul></div>';
        }

        echo '<p><em>Dica:</em> Se pretenderes “atualizar” registos no próximo upload, inclui uma coluna <code>external_ref</code> estável por local — assim evitamos duplicados e fazemos update.</p>';
    }
}
