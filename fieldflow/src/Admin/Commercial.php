<?php
namespace RoutesPro\Admin;

use RoutesPro\Support\GeoPT;
use RoutesPro\Services\LocationDeduplicator;

if (!defined('ABSPATH')) exit;

class Commercial {
    public static function render(): void {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb; $px = $wpdb->prefix . 'routespro_';

        self::handle_post();
        self::handle_merge_duplicates();

        $district = sanitize_text_field($_GET['district'] ?? '');
        $county = sanitize_text_field($_GET['county'] ?? '');
        $city = sanitize_text_field($_GET['city'] ?? '');
        $category_id = absint($_GET['category_id'] ?? 0);
        $subcategory_id = absint($_GET['subcategory_id'] ?? 0);
        $q = sanitize_text_field($_GET['q'] ?? '');
        $edit_id = absint($_GET['edit_id'] ?? 0);
        $project_id = absint($_GET['project_id'] ?? 0);
        $client_id = absint($_GET['client_id'] ?? 0);
        $projects = $wpdb->get_results("SELECT id,name,client_id FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: [];
        $clients = $wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
        $edit_row = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}locations WHERE id=%d LIMIT 1", $edit_id), ARRAY_A) : null;
        $edit_category_id = (int)($edit_row['category_id'] ?? 0);
        $edit_subcategory_id = (int)($edit_row['subcategory_id'] ?? 0);
        if ($edit_category_id && !$edit_subcategory_id) {
            $maybeChild = $wpdb->get_row($wpdb->prepare("SELECT id,parent_id FROM {$px}categories WHERE id=%d LIMIT 1", $edit_category_id), ARRAY_A);
            if ($maybeChild && !empty($maybeChild['parent_id'])) {
                $edit_subcategory_id = (int)$maybeChild['id'];
                $edit_category_id = (int)$maybeChild['parent_id'];
            }
        }
        if ($edit_subcategory_id && !$edit_category_id) {
            $parentId = (int)$wpdb->get_var($wpdb->prepare("SELECT parent_id FROM {$px}categories WHERE id=%d LIMIT 1", $edit_subcategory_id));
            if ($parentId) $edit_category_id = $parentId;
        }
        $where = ['1=1']; $args = [];
        if ($district !== '') { $where[] = 'l.district=%s'; $args[] = $district; }
        if ($county !== '') { $where[] = 'l.county=%s'; $args[] = $county; }
        if ($city !== '') { $where[] = 'l.city=%s'; $args[] = $city; }
        if ($subcategory_id) {
            $where[] = '(l.subcategory_id=%d OR (l.subcategory_id IS NULL AND l.category_id=%d))';
            $args[] = $subcategory_id;
            $args[] = $subcategory_id;
        }
        elseif ($category_id) { $where[] = '(l.category_id=%d OR parent_cat.id=%d OR legacy_parent_cat.parent_id=%d OR l.subcategory_id IN (SELECT id FROM {$px}categories WHERE parent_id=%d))'; $args[] = $category_id; $args[] = $category_id; $args[] = $category_id; $args[] = $category_id; }
        if ($project_id) { $where[] = '(cl.project_id=%d OR l.project_id=%d)'; $args[] = $project_id; $args[] = $project_id; }
        elseif ($client_id) { $where[] = '(clp.client_id=%d OR l.client_id=%d)'; $args[] = $client_id; $args[] = $client_id; }
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.phone LIKE %s OR l.email LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }

        $sql = "SELECT l.*, c.name AS category_name, sc.name AS subcategory_name, COALESCE(parent_cat.id, legacy_parent.id) AS parent_category_id, COALESCE(parent_cat.name, legacy_parent.name) AS parent_category_name
                FROM {$px}locations l
                LEFT JOIN {$px}campaign_locations cl ON cl.location_id=l.id
                LEFT JOIN {$px}projects clp ON clp.id=cl.project_id
                LEFT JOIN {$px}categories c ON c.id=l.category_id
                LEFT JOIN {$px}categories legacy_parent_cat ON legacy_parent_cat.id=l.category_id
                LEFT JOIN {$px}categories legacy_parent ON legacy_parent.id=legacy_parent_cat.parent_id
                LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id
                LEFT JOIN {$px}categories parent_cat ON parent_cat.id=sc.parent_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.updated_at DESC, l.id DESC
                LIMIT 300";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $rows = array_map([self::class, 'normalize_result_row'], LocationDeduplicator::dedupe_rows($rows ?: []));

        $categories = $wpdb->get_results("SELECT id,name,parent_id,slug FROM {$px}categories WHERE is_active=1 ORDER BY sort_order ASC, name ASC", ARRAY_A);
        $root_categories = [];
        $subcategories_by_parent = [];
        $seen_root = [];
        foreach ($categories as $cat) {
            $key = strtolower(trim((string)($cat['name'] ?: $cat['slug'] ?: '')));
            if ((int)($cat['parent_id'] ?? 0) === 0) {
                if ($key === '' || isset($seen_root[$key])) continue;
                $seen_root[$key] = true;
                $root_categories[] = $cat;
            } else {
                $pid = (int)$cat['parent_id'];
                if (!isset($subcategories_by_parent[$pid])) $subcategories_by_parent[$pid] = [];
                $sub_key = strtolower(trim((string)($cat['name'] ?? '')));
                $exists = false;
                foreach ($subcategories_by_parent[$pid] as $existing) {
                    if (strtolower(trim((string)($existing['name'] ?? ''))) === $sub_key) { $exists = true; break; }
                }
                if ($sub_key !== '' && !$exists) $subcategories_by_parent[$pid][] = $cat;
            }
        }
        $districts = GeoPT::districts();
        $countiesByDistrict = GeoPT::counties_by_district();
        $citiesByDistrict = GeoPT::cities_by_district();

        echo '<div class="wrap">';
        Branding::render_header('Base Comercial');

        echo '<div class="routespro-card" style="margin-bottom:18px">';
        echo '<form method="get" class="routespro-flex" id="rp-commercial-filter-form">';
        echo '<input type="hidden" name="page" value="routespro-commercial" />';
        echo '<select name="client_id" id="rp-commercial-filter-client"><option value="">Cliente</option>';
        foreach ($clients as $c) echo '<option value="'.intval($c['id']).'" '.selected($client_id,$c['id'],false).'>'.esc_html($c['name']).'</option>';
        echo '</select>';
        echo '<select name="project_id" id="rp-commercial-filter-project"><option value="">Campanha</option>';
        foreach ($projects as $p) echo '<option value="'.intval($p['id']).'" data-client="'.intval($p['client_id']).'" '.selected($project_id,$p['id'],false).'>'.esc_html($p['name']).'</option>';
        echo '</select>';
        self::render_select('district', 'rp-commercial-filter-district', 'Distrito', $districts, $district);
        self::render_select('county', 'rp-commercial-filter-county', 'Concelho', $district ? ($countiesByDistrict[$district] ?? []) : GeoPT::all_counties(), $county);
        self::render_select('city', 'rp-commercial-filter-city', 'Cidade', $district ? ($citiesByDistrict[$district] ?? []) : GeoPT::all_cities(), $city);
        echo '<select name="category_id" id="rp-commercial-filter-category"><option value="">Categoria</option>';
        foreach ($root_categories as $cat) echo '<option value="'.intval($cat['id']).'" '.selected($category_id,$cat['id'],false).'>'.esc_html($cat['name']).'</option>';
        echo '</select>';
        echo '<select name="subcategory_id" id="rp-commercial-filter-subcategory"><option value="">Subcategoria</option>';
        foreach (($category_id ? ($subcategories_by_parent[$category_id] ?? []) : []) as $subcat) {
            echo '<option value="'.intval($subcat['id']).'" '.selected($subcategory_id,$subcat['id'],false).'>'.esc_html($subcat['name']).'</option>';
        }
        echo '</select>';
        echo '<input type="search" name="q" id="rp-commercial-q" value="'.esc_attr($q).'" placeholder="Pesquisar nome, morada, telefone ou email" style="min-width:280px" />';
        echo '<button class="button button-primary">Filtrar</button>';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=routespro-commercial')).'">Limpar</a>';
        echo '</form>';
        echo '</div>';

        $total = count($rows);
        $with_coords = 0;
        foreach ($rows as $r) if ($r['lat'] !== null && $r['lng'] !== null) $with_coords++;
        echo '<div class="routespro-meta-grid">';
        echo '<div class="routespro-card"><strong>Total visível</strong><div id="rp-commercial-stat-total" style="font-size:26px;margin-top:6px">'.intval($total).'</div></div>';
        echo '<div class="routespro-card"><strong>Com coordenadas</strong><div id="rp-commercial-stat-coords" style="font-size:26px;margin-top:6px">'.intval($with_coords).'</div></div>';
        echo '<div class="routespro-card"><strong>Origem Google</strong><div id="rp-commercial-stat-google" style="font-size:26px;margin-top:6px">'.intval(count(array_filter($rows, fn($x) => ($x['source'] ?? '') === 'google'))).'</div></div>';
        echo '<div class="routespro-card"><strong>Validados</strong><div id="rp-commercial-stat-validated" style="font-size:26px;margin-top:6px">'.intval(count(array_filter($rows, fn($x) => intval($x['is_validated'] ?? 0) === 1))).'</div></div>';
        echo '</div>';
        echo '<div id="rp-commercial-results-summary" class="description" style="margin:8px 0 16px">A mostrar '.intval($total).' PDVs filtrados.</div>';

        echo '<div class="routespro-card" style="margin-bottom:18px">';
        echo '<div class="routespro-flex" style="justify-content:space-between;margin-bottom:10px"><h2 style="margin:0">Mapa Comercial</h2><div class="routespro-flex"><button type="button" class="button" id="rp-commercial-refresh">Atualizar mapa</button><button type="button" class="button" id="rp-google-discovery">Descoberta Google</button></div></div>';
        echo '<div id="rp-commercial-map" class="routespro-map"></div>';
        echo '<p class="description" style="margin-top:8px">Clicar num local do mapa ou da lista preenche automaticamente o formulário de PDV abaixo.</p>';
        echo '</div>';

        echo '<div class="routespro-card" style="margin-bottom:18px">';
        echo '<h2 style="margin-top:0">Novo PDV</h2>';
        echo '<form method="post" id="rp-commercial-pdv-form">';
        echo '<input type="hidden" name="client_id" id="rp-pdv-client-id" value="'.intval($client_id).'" />';
        echo '<input type="hidden" name="project_id" id="rp-pdv-project-id" value="'.intval($project_id).'" />';
        wp_nonce_field('routespro_commercial_save', 'routespro_commercial_nonce');
        echo '<input type="hidden" name="action_type" value="save_pdv" />';
        echo '<input type="hidden" name="location_id" id="rp-pdv-id" value="'.intval($edit_row['id'] ?? 0).'" />';
        echo '<table class="form-table"><tbody>';
        self::field('Nome', '<input type="text" name="name" id="rp-pdv-name" class="regular-text" value="'.esc_attr($edit_row['name'] ?? '').'" required />');
        self::field('Morada', '<input type="text" name="address" id="rp-pdv-address" class="regular-text" value="'.esc_attr($edit_row['address'] ?? '').'" /><p class="description">Autocomplete Google ativo e preenchimento automático da geografia e telefone, quando disponível.</p>');
        self::field('Distrito', '<input type="text" name="district" id="rp-pdv-district" class="regular-text" value="'.esc_attr((string)($edit_row['district'] ?? '')).'" placeholder="Distrito" />');
        self::field('Concelho', '<input type="text" name="county" id="rp-pdv-county" class="regular-text" value="'.esc_attr((string)($edit_row['county'] ?? '')).'" placeholder="Concelho" />');
        self::field('Cidade', '<input type="text" name="city" id="rp-pdv-city" class="regular-text" value="'.esc_attr((string)($edit_row['city'] ?? '')).'" placeholder="Cidade" />');
        self::field('Freguesia', '<input type="text" name="parish" id="rp-pdv-parish" class="regular-text" value="'.esc_attr((string)($edit_row['parish'] ?? '')).'" placeholder="Freguesia" />');
        self::field('Código Postal', '<input type="text" name="postal_code" id="rp-pdv-postal-code" class="regular-text" value="'.esc_attr((string)($edit_row['postal_code'] ?? '')).'" placeholder="Código Postal" />');
        self::field('País', '<input type="text" name="country" id="rp-pdv-country" class="regular-text" value="'.esc_attr((string)($edit_row['country'] ?? 'Portugal')).'" placeholder="País" />');
        $cats_html = '<select name="category_id" id="rp-pdv-category"><option value="">--</option>';
        foreach ($root_categories as $cat) $cats_html .= '<option value="'.intval($cat['id']).'" '.selected($edit_category_id,(int)$cat['id'],false).'>'.esc_html($cat['name']).'</option>';
        $cats_html .= '</select>';
        self::field('Categoria', $cats_html);
        $subcats_html = '<select name="subcategory_id" id="rp-pdv-subcategory" data-selected="'.intval($edit_subcategory_id).'"><option value="">--</option></select>';
        self::field('Subcategoria', $subcats_html);
        self::field('Telefone', '<input type="text" name="phone" id="rp-pdv-phone" class="regular-text" value="'.esc_attr($edit_row['phone'] ?? '').'" />');
        self::field('Contacto', '<input type="text" name="contact_person" id="rp-pdv-contact-person" class="regular-text" value="'.esc_attr($edit_row['contact_person'] ?? '').'" />');
        self::field('Email', '<input type="email" name="email" id="rp-pdv-email" class="regular-text" value="'.esc_attr($edit_row['email'] ?? '').'" />');
        self::field('Website', '<input type="url" name="website" id="rp-pdv-website" class="regular-text" value="'.esc_attr((string)($edit_row['website'] ?? '')).'" placeholder="https://" />');
        self::field('Referência Externa', '<input type="text" name="external_ref" id="rp-pdv-external-ref" class="regular-text" value="'.esc_attr((string)($edit_row['external_ref'] ?? '')).'" />');
        self::field('Lat / Lng', '<div class="routespro-flex"><input type="text" name="lat" id="rp-pdv-lat" class="regular-text" placeholder="Lat" value="'.esc_attr($edit_row['lat'] ?? '').'" /><input type="text" name="lng" id="rp-pdv-lng" class="regular-text" placeholder="Lng" value="'.esc_attr($edit_row['lng'] ?? '').'" /></div><p class="description">Distrito, concelho, cidade, freguesia, código postal, place_id e origem são preenchidos automaticamente.</p>');
        echo '<input type="hidden" name="place_id" id="rp-pdv-place-id" value="'.esc_attr($edit_row['place_id'] ?? '').'" />';
        echo '<input type="hidden" name="source" id="rp-pdv-source" value="'.esc_attr((string)($edit_row['source'] ?? 'manual')).'" />';
        echo '</tbody></table>';
        echo '<p><button class="button button-primary">'.($edit_row ? 'Atualizar PDV' : 'Guardar PDV').'</button> '.($edit_row ? '<a class="button" href="'.esc_url(admin_url('admin.php?page=routespro-commercial')).'">Cancelar edição</a>' : '').'</p>';
        echo '</form>';
        echo '</div>';

        echo '<div class="routespro-card">';
        $exportUrl = add_query_arg(array_filter(['action'=>'routespro_export_commercial_existing','client_id'=>$client_id ?: null,'project_id'=>$project_id ?: null]), admin_url('admin-post.php'));
        echo '<div class="routespro-flex" style="justify-content:space-between"><h2 style="margin:0">PDVs</h2><div class="routespro-flex"><a class="button" href="'.esc_url($exportUrl).'">Exportar existentes CSV</a><a class="button" href="'.esc_url(admin_url('admin-post.php?action=routespro_download_commercial_template')).'">Descarregar template CSV</a></div></div>';
        echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>ID</th><th>Nome</th><th>Categoria</th><th>Subcategoria</th><th>Distrito</th><th>Concelho</th><th>Cidade</th><th>Telefone</th><th>Origem</th><th>Validado</th><th>Ação</th></tr></thead><tbody id="rp-commercial-table-body">';
        if (!$rows) {
            echo '<tr><td colspan="11">Sem resultados.</td></tr>';
        } else {
            foreach ($rows as $r) {
                $payload = rawurlencode(wp_json_encode($r));
                echo '<tr>';
                echo '<td>'.intval($r['id']).'</td>';
                echo '<td><strong>'.esc_html($r['name']).'</strong><br><span style="color:#6b7280">'.esc_html((string)$r['address']).'</span></td>';
                echo '<td>'.esc_html((string)$r['category_name']).'</td>';
                echo '<td>'.esc_html((string)$r['subcategory_name']).'</td>';
                echo '<td>'.esc_html((string)$r['district']).'</td>';
                echo '<td>'.esc_html((string)$r['county']).'</td>';
                echo '<td>'.esc_html((string)$r['city']).'</td>';
                echo '<td>'.esc_html((string)$r['phone']).'</td>';
                echo '<td>'.esc_html((string)$r['source']).'</td>';
                echo '<td>'.(intval($r['is_validated']) ? 'Sim' : 'Não').'</td>';
                echo '<td><button type="button" class="button button-small rp-use-pdv" data-item="'.$payload.'">Usar</button> <a class="button button-small" href="'.esc_url(add_query_arg(['page'=>'routespro-commercial','edit_id'=>(int)$r['id']], admin_url('admin.php'))).'">Editar</a> <form method="post" style="display:inline" onsubmit="return confirm(\'Apagar este PDV da base comercial?\');">'.wp_nonce_field('routespro_commercial_delete','routespro_commercial_delete_nonce',true,false).'<input type="hidden" name="action_type" value="delete_pdv"><input type="hidden" name="location_id" value="'.intval($r['id']).'"><button type="submit" class="button button-small">Apagar</button></form></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="routespro-card" style="margin-top:18px">';
        echo '<div class="routespro-flex" style="justify-content:space-between"><h2 style="margin:0">Descoberta Google</h2><p class="description" style="margin:0">Resultados externos por zona e categoria, para usar ou importar.</p></div>';
        echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>Nome</th><th>Morada</th><th>Zona</th><th>Ação</th></tr></thead><tbody id="rp-google-results-body"><tr><td colspan="4">Sem resultados Google carregados.</td></tr></tbody></table>';
        echo '</div>';

        self::render_duplicates_panel();
        self::render_map_script($rows, $countiesByDistrict, $citiesByDistrict, $subcategories_by_parent);
        echo '</div>';
    }

    public static function render_import(): void {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $client_id = absint($_REQUEST['client_id'] ?? 0);
        $project_id = absint($_REQUEST['project_id'] ?? 0);
        $clients = $wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
        $projects = $wpdb->get_results("SELECT id,name,client_id FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: [];
        echo '<div class="wrap">';
        Branding::render_header('Importar PDVs');
        if (!empty($_POST['routespro_commercial_import_nonce']) && wp_verify_nonce($_POST['routespro_commercial_import_nonce'], 'routespro_commercial_import')) {
            self::handle_import();
        }
        echo '<div class="routespro-card">';
        echo '<h2 style="margin-top:0">CSV bulk</h2>';
        echo '<p>Importa novos locais comerciais ou atualiza registos existentes por <code>place_id</code>, <code>external_ref</code>, <code>email</code>, <code>phone</code> ou combinação nome + morada.</p>';
        echo '<form method="get" style="margin-bottom:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:end">';
        echo '<input type="hidden" name="page" value="routespro-commercial-import">';
        echo '<label>Cliente<br><select name="client_id" id="rp-commercial-import-client"><option value="">Todos</option>';
        foreach ($clients as $c) echo '<option value="'.intval($c['id']).'" '.selected($client_id,$c['id'],false).'>'.esc_html($c['name']).'</option>';
        echo '</select></label>';
        echo '<label>Campanha<br><select name="project_id" id="rp-commercial-import-project"><option value="">Todas</option>';
        foreach ($projects as $p) echo '<option value="'.intval($p['id']).'" data-client="'.intval($p['client_id']).'" '.selected($project_id,$p['id'],false).'>'.esc_html($p['name']).'</option>';
        echo '</select></label>';
        echo '<button class="button">Aplicar filtros</button>';
        echo '</form>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('routespro_commercial_import', 'routespro_commercial_import_nonce');
        echo '<input type="file" name="csv_file" accept=".csv,text/csv" required /> ';
        echo '<button class="button button-primary">Importar CSV</button> ';
        $exportUrl = add_query_arg(array_filter(['action'=>'routespro_export_commercial_existing','client_id'=>$client_id ?: null,'project_id'=>$project_id ?: null]), admin_url('admin-post.php'));
        echo '<a class="button" href="'.esc_url($exportUrl).'">Exportar existentes CSV</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin-post.php?action=routespro_download_commercial_template')).'">Descarregar template CSV</a>';
        echo '</form>';
        echo '<script>(function(){var c=document.getElementById("rp-commercial-import-client"),p=document.getElementById("rp-commercial-import-project");if(!c||!p)return;function sync(){var v=c.value;Array.from(p.options).forEach(function(o,i){if(i===0)return;o.hidden=!!v&&o.dataset.client!==v;});if(p.selectedOptions&&p.selectedOptions[0]&&p.selectedOptions[0].hidden)p.value="";}c.addEventListener("change",sync);sync();})();</script>';
        echo '</div></div>';
    }


    private static function subcategory_aliases(string $name): array {
        $map = [
            'continente' => ['continente', 'continente modelo'],
            'continente bom dia' => ['continente bom dia'],
            'pingo doce' => ['pingo doce'],
            'auchan' => ['auchan', 'jumbo'],
            'mercadona' => ['mercadona'],
            'e.leclerc' => ['e.leclerc', 'eleclerc', 'leclerc'],
            'minipreço' => ['minipreço', 'mini preco', 'mini preço'],
            'lidl' => ['lidl'],
            'aldi' => ['aldi'],
            'intermarché' => ['intermarché', 'intermarche'],
            'super / hiper poupança' => ['poupança', 'poupanca', 'super poupança', 'super poupanca', 'hiper poupança', 'hiper poupanca'],
            'worten' => ['worten'],
            'fnac' => ['fnac'],
            'radio popular' => ['radio popular', 'rádio popular'],
            'staples' => ['staples'],
            'darty' => ['darty'],
            'makro' => ['makro', 'macro'],
            'recheio' => ['recheio'],
            'mcunha' => ['mcunha', 'm cunha'],
            'marabuto' => ['marabuto', 'matarabuto'],
            'malaquias' => ['malaquias'],
            'grossão' => ['grossão', 'grossao'],
            'nortenho' => ['nortenho'],
            'pereira e santos' => ['pereira e santos'],
            'a. ezequiel' => ['a. ezequiel', 'a ezequiel'],
            'garcias' => ['garcias'],
            'arcol' => ['arcol'],
        ];
        $key = strtolower(trim($name));
        return $map[$key] ?? ($key !== '' ? [$name] : []);
    }

    private static function render_select(string $name, string $id, string $placeholder, array $options, string $selected): void {
        echo self::select_html($name, $id, $placeholder, $options, $selected);
    }

    private static function select_html(string $name, string $id, string $placeholder, array $options, string $selected): string {
        $html = '<select name="'.esc_attr($name).'" id="'.esc_attr($id).'">';
        $html .= '<option value="">'.esc_html($placeholder).'</option>';
        foreach ($options as $v) $html .= '<option value="'.esc_attr($v).'" '.selected($selected, $v, false).'>'.esc_html($v).'</option>';
        $html .= '</select>';
        return $html;
    }

    private static function field(string $label, string $control): void {
        echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.$control.'</td></tr>';
    }

    private static function handle_post(): void {
        $action = sanitize_text_field($_POST['action_type'] ?? '');
        if ($action === 'delete_pdv') {
            if (!current_user_can('routespro_manage')) return;
            if (empty($_POST['routespro_commercial_delete_nonce']) || !wp_verify_nonce($_POST['routespro_commercial_delete_nonce'], 'routespro_commercial_delete')) return;
            global $wpdb; $px = $wpdb->prefix . 'routespro_';
            $location_id = absint($_POST['location_id'] ?? 0);
            if ($location_id) {
                if ($wpdb->get_var("SHOW TABLES LIKE '{$px}campaign_locations'") === $px.'campaign_locations') $wpdb->delete($px.'campaign_locations', ['location_id'=>$location_id]);
                $wpdb->delete($px.'route_stops', ['location_id'=>$location_id]);
                $wpdb->delete($px.'route_location_snapshot', ['location_id'=>$location_id]);
                $wpdb->delete($px.'locations', ['id'=>$location_id]);
                echo '<div class="notice notice-success"><p>PDV apagado com sucesso.</p></div>';
            }
            return;
        }
        if ($action !== 'save_pdv') return;
        if (!current_user_can('routespro_manage')) return;
        if (empty($_POST['routespro_commercial_nonce']) || !wp_verify_nonce($_POST['routespro_commercial_nonce'], 'routespro_commercial_save')) return;
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $location_id = absint($_POST['location_id'] ?? 0);
        $data = [
            'client_id' => absint($_POST['client_id'] ?? 0) ?: null,
            'project_id' => absint($_POST['project_id'] ?? 0) ?: null,
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'address' => sanitize_textarea_field($_POST['address'] ?? ''),
            'district' => sanitize_text_field($_POST['district'] ?? ''),
            'county' => sanitize_text_field($_POST['county'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'parish' => sanitize_text_field($_POST['parish'] ?? ''),
            'postal_code' => sanitize_text_field($_POST['postal_code'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? 'Portugal'),
            'category_id' => absint($_POST['category_id'] ?? 0),
            'subcategory_id' => absint($_POST['subcategory_id'] ?? 0),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'website' => esc_url_raw($_POST['website'] ?? ''),
            'external_ref' => sanitize_text_field($_POST['external_ref'] ?? ''),
            'contact_person' => sanitize_text_field($_POST['contact_person'] ?? '') ?: sanitize_text_field($_POST['name'] ?? ''),
            'place_id' => sanitize_text_field($_POST['place_id'] ?? ''),
            'lat' => ($_POST['lat'] ?? '') !== '' ? (float) $_POST['lat'] : null,
            'lng' => ($_POST['lng'] ?? '') !== '' ? (float) $_POST['lng'] : null,
            'location_type' => 'pdv',
            'source' => sanitize_text_field($_POST['source'] ?? 'manual'),
            'is_active' => 1,
            'updated_at' => current_time('mysql'),
        ];
        $normalized_category = self::normalize_category_pair((int)$data['category_id'], (int)$data['subcategory_id']);
        $data['category_id'] = $normalized_category['category_id'];
        $data['subcategory_id'] = $normalized_category['subcategory_id'];
        $data = \RoutesPro\Services\LocationDeduplicator::filter_location_payload($data);
        if (($data['name'] ?? '') === '') {
            echo '<div class="notice notice-error"><p>O nome do PDV é obrigatório.</p></div>';
            return;
        }
        if ($location_id) {
            $wpdb->update($px.'locations', $data, ['id' => $location_id]);
            $result = ['id'=>$location_id,'existing'=>true,'reason'=>'updated'];
        } else {
            $result = \RoutesPro\Services\LocationDeduplicator::upsert($data, 0, true);
        }
        $saved_location_id = (int)($result['id'] ?? $location_id);
        $saved_project_id = (int)($data['project_id'] ?? 0);
        if ($saved_location_id && $saved_project_id && $wpdb->get_var("SHOW TABLES LIKE '{$px}campaign_locations'") === $px.'campaign_locations') {
            $link = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$px}campaign_locations WHERE project_id=%d AND location_id=%d LIMIT 1", $saved_project_id, $saved_location_id));
            if ($link) {
                $wpdb->update($px.'campaign_locations', ['is_active' => 1, 'status' => 'active'], ['id' => (int)$link]);
            } else {
                $wpdb->insert($px.'campaign_locations', ['project_id' => $saved_project_id, 'location_id' => $saved_location_id, 'status' => 'active', 'is_active' => 1]);
            }
        }
        if (!empty($result['existing'])) {
            echo '<div class="notice notice-warning"><p>O PDV já existia e foi atualizado no registo existente.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>PDV guardado com sucesso.</p></div>';
        }
    }

    private static function handle_import(): void {
        if (empty($_FILES['csv_file']['tmp_name'])) {
            echo '<div class="notice notice-error"><p>Seleciona um ficheiro CSV.</p></div>';
            return;
        }
        $tmp = $_FILES['csv_file']['tmp_name'];
        $rows = [];
        if (($fh = fopen($tmp, 'r')) !== false) {
            $headers = fgetcsv($fh, 0, ',');
            while (($line = fgetcsv($fh, 0, ',')) !== false) $rows[] = array_combine($headers, $line);
            fclose($fh);
        }
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $inserted = 0; $updated = 0; $skipped = 0; $errors = 0;
        foreach ($rows as $assoc) {
            if (!is_array($assoc)) { $errors++; continue; }
            $name = sanitize_text_field($assoc['name'] ?? '');
            if ($name === '') { $skipped++; continue; }
            $category_pair = self::resolve_category_pair((string)($assoc['category'] ?? ''), (string)($assoc['subcategory'] ?? ''));
            $data = [
                'name' => $name,
                'address' => sanitize_text_field($assoc['address'] ?? ''),
                'district' => sanitize_text_field($assoc['district'] ?? ''),
                'county' => sanitize_text_field($assoc['county'] ?? ''),
                'city' => sanitize_text_field($assoc['city'] ?? ''),
                'parish' => sanitize_text_field($assoc['parish'] ?? ''),
                'postal_code' => sanitize_text_field($assoc['postal_code'] ?? ''),
                'country' => sanitize_text_field($assoc['country'] ?? 'Portugal'),
                'category_id' => ($category_pair['category_id'] ?: null),
                'subcategory_id' => ($category_pair['subcategory_id'] ?: null),
                'phone' => sanitize_text_field($assoc['phone'] ?? ''),
                'email' => sanitize_email($assoc['email'] ?? ''),
                'website' => esc_url_raw($assoc['website'] ?? ''),
                'lat' => ($assoc['lat'] ?? '') !== '' ? (float)$assoc['lat'] : null,
                'lng' => ($assoc['lng'] ?? '') !== '' ? (float)$assoc['lng'] : null,
                'external_ref' => sanitize_text_field($assoc['external_ref'] ?? ''),
                'place_id' => sanitize_text_field($assoc['place_id'] ?? ''),
                'source' => sanitize_text_field($assoc['source'] ?? 'csv'),
                'location_type' => 'pdv',
                'is_active' => 1,
                'updated_at' => current_time('mysql'),
            ];
            $result = LocationDeduplicator::upsert($data, 0, true);
            if (!empty($result['existing'])) $updated++; else $inserted++;
        }
        echo '<div class="notice notice-success"><p>Import concluído. Inseridos: '.intval($inserted).', atualizados: '.intval($updated).', ignorados: '.intval($skipped).', erros: '.intval($errors).'.</p></div>';
    }

    private static function resolve_category(string $category, string $subcategory = ''): int {
        $pair = self::resolve_category_pair($category, $subcategory);
        return (int)($pair['subcategory_id'] ?: $pair['category_id']);
    }

    private static function resolve_category_pair(string $category, string $subcategory = ''): array {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $category = trim($category);
        $subcategory = trim($subcategory);
        if ($category === '' && $subcategory === '') return ['category_id' => 0, 'subcategory_id' => 0];
        $parent = 0;
        if ($category !== '') {
            $parent = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$px}categories WHERE name=%s AND parent_id IS NULL LIMIT 1", $category));
            if (!$parent) {
                $wpdb->insert($px.'categories', ['name' => $category, 'slug' => sanitize_title($category), 'is_active' => 1]);
                $parent = (int)$wpdb->insert_id;
            }
        }
        $child = 0;
        if ($subcategory !== '') {
            $child = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$px}categories WHERE name=%s AND parent_id=%d LIMIT 1", $subcategory, $parent));
            if (!$child) {
                $wpdb->insert($px.'categories', ['parent_id' => $parent ?: null, 'name' => $subcategory, 'slug' => sanitize_title($subcategory), 'is_active' => 1]);
                $child = (int)$wpdb->insert_id;
            }
        }
        return ['category_id' => $parent, 'subcategory_id' => $child];
    }

    private static function normalize_category_pair(int $category_id, int $subcategory_id = 0): array {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $category_id = (int)$category_id;
        $subcategory_id = (int)$subcategory_id;

        if ($subcategory_id) {
            $parent_id = (int)$wpdb->get_var($wpdb->prepare("SELECT parent_id FROM {$px}categories WHERE id=%d LIMIT 1", $subcategory_id));
            if ($parent_id) $category_id = $parent_id;
            return ['category_id' => $category_id ?: null, 'subcategory_id' => $subcategory_id ?: null];
        }

        if ($category_id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id,parent_id FROM {$px}categories WHERE id=%d LIMIT 1", $category_id), ARRAY_A);
            if ($row && !empty($row['parent_id'])) {
                return ['category_id' => (int)$row['parent_id'], 'subcategory_id' => (int)$row['id']];
            }
        }

        return ['category_id' => $category_id ?: null, 'subcategory_id' => null];
    }

    private static function normalize_result_row(array $row): array {
        $category_id = (int)($row['category_id'] ?? 0);
        $subcategory_id = (int)($row['subcategory_id'] ?? 0);
        $category_name = (string)($row['category_name'] ?? '');
        $subcategory_name = (string)($row['subcategory_name'] ?? '');
        $parent_category_id = (int)($row['parent_category_id'] ?? 0);
        $parent_category_name = (string)($row['parent_category_name'] ?? '');

        if ($subcategory_id && $parent_category_id) {
            $row['category_id'] = $parent_category_id;
            $row['category_name'] = $parent_category_name !== '' ? $parent_category_name : $category_name;
            $row['effective_category_id'] = $parent_category_id;
            $row['effective_subcategory_id'] = $subcategory_id;
            return $row;
        }

        if ($category_id && !$subcategory_id && $parent_category_id) {
            $row['category_id'] = $parent_category_id;
            $row['category_name'] = $parent_category_name !== '' ? $parent_category_name : $category_name;
            $row['subcategory_id'] = $category_id;
            $row['subcategory_name'] = $category_name;
            $row['effective_category_id'] = $parent_category_id;
            $row['effective_subcategory_id'] = $category_id;
            return $row;
        }

        $row['effective_category_id'] = (int)($row['category_id'] ?? 0);
        $row['effective_subcategory_id'] = (int)($row['subcategory_id'] ?? 0);
        return $row;
    }

    private static function handle_merge_duplicates(): void {
        if (empty($_POST['action_type']) || $_POST['action_type'] !== 'merge_duplicate_group') return;
        if (!current_user_can('routespro_manage')) return;
        if (empty($_POST['routespro_commercial_nonce']) || !wp_verify_nonce($_POST['routespro_commercial_nonce'], 'routespro_commercial_save')) return;
        $master_id = absint($_POST['master_id'] ?? 0);
        $duplicate_ids = array_values(array_filter(array_map('absint', (array)($_POST['duplicate_ids'] ?? []))));
        if ($master_id && $duplicate_ids) {
            \RoutesPro\Services\LocationDeduplicator::merge_group($master_id, $duplicate_ids);
            echo '<div class="notice notice-success"><p>Duplicados fundidos com sucesso.</p></div>';
        }
    }

    private static function render_duplicates_panel(): void {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $rows = $wpdb->get_results("SELECT id,name,address,phone,email,place_id,updated_at FROM {$px}locations ORDER BY updated_at DESC, id DESC LIMIT 500", ARRAY_A);
        if (!$rows) return;
        $groups = [];
        foreach ($rows as $row) {
            $key = '';
            if (!empty($row['place_id'])) {
                $key = 'place_id:' . strtolower(trim((string)$row['place_id']));
            } elseif (!empty($row['email'])) {
                $key = 'email:' . strtolower(trim((string)$row['email']));
            } elseif (!empty($row['phone'])) {
                $key = 'phone:' . preg_replace('/\D+/', '', (string)$row['phone']);
            } else {
                $name = strtolower(trim((string)($row['name'] ?? '')));
                $addr = strtolower(trim((string)($row['address'] ?? '')));
                if ($name !== '' && $addr !== '') $key = 'name_address:' . $name . '|' . $addr;
            }
            if ($key === '') continue;
            $groups[$key][] = $row;
        }
        $groups = array_values(array_filter($groups, function($g){ return count($g) > 1; }));
        if (!$groups) return;
        echo '<div class="routespro-card" style="margin-top:18px">';
        echo '<div class="routespro-flex" style="justify-content:space-between"><h2 style="margin:0">Duplicados potenciais</h2><p class="description" style="margin:0">Grupos detetados automaticamente. Podes fundir mantendo o registo mais recente.</p></div>';
        foreach (array_slice($groups, 0, 20) as $idx => $group) {
            usort($group, function($a,$b){ return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')); });
            $master = $group[0];
            $duplicate_ids = array_map(function($r){ return (int)$r['id']; }, array_slice($group, 1));
            echo '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-top:12px;background:#fff">';
            echo '<strong>Grupo '.intval($idx+1).'</strong><ul style="margin:8px 0 10px 18px">';
            foreach ($group as $r) {
                echo '<li>#'.intval($r['id']).' '.esc_html((string)$r['name']).' <span style="color:#6b7280">'.esc_html((string)$r['address']).'</span></li>';
            }
            echo '</ul>';
            echo '<form method="post">';
            wp_nonce_field('routespro_commercial_save', 'routespro_commercial_nonce');
            echo '<input type="hidden" name="action_type" value="merge_duplicate_group" />';
            echo '<input type="hidden" name="master_id" value="'.intval($master['id']).'" />';
            foreach ($duplicate_ids as $dup_id) echo '<input type="hidden" name="duplicate_ids[]" value="'.intval($dup_id).'" />';
            echo '<button class="button">Fundir no mais recente (#'.intval($master['id']).')</button>';
            echo '</form></div>';
        }
        echo '</div>';
    }

    private static function render_map_script(array $rows, array $countiesByDistrict, array $citiesByDistrict, array $subcategoriesByParent): void {
        $api = esc_url(rest_url('routespro/v1/'));
        $nonce = wp_create_nonce('wp_rest');
        $delete_nonce = wp_create_nonce('routespro_commercial_delete');
        $opts = Settings::get();
        $google_key = trim((string) ($opts['google_maps_key'] ?? ''));
        $google_script = $google_key !== '' ? '<script src="https://maps.googleapis.com/maps/api/js?key=' . rawurlencode($google_key) . '&libraries=places&loading=async"></script>' : '';
        echo $google_script;
        $api_json = wp_json_encode($api);
        $nonce_json = wp_json_encode($nonce);
        $admin_base_json = wp_json_encode(admin_url('admin.php?page=routespro-commercial'));

        $counties_json = wp_json_encode($countiesByDistrict);
        $cities_json = wp_json_encode($citiesByDistrict);
        $subcats_json = wp_json_encode($subcategoriesByParent);
        $initial_rows_json = wp_json_encode(array_values($rows));
        $google_available = $google_key !== '' ? 'true' : 'false';
        $delete_nonce_json = wp_json_encode($delete_nonce);
        $edit_county_json = wp_json_encode((string)($rows[0]['county'] ?? ''));
        echo <<<HTML
<script>
document.addEventListener("DOMContentLoaded", async function(){
    const api = {$api_json};
    const nonce = {$nonce_json};
    const deleteNonce = {$delete_nonce_json};
    const adminBase = {$admin_base_json};
    const ajaxData = {adminUrl: ""};
    const googleKeyAvailable = {$google_available};
    const countiesByDistrict = {$counties_json};
    const citiesByDistrict = {$cities_json};
    const subcategoriesByParent = {$subcats_json};
    const initialRows = {$initial_rows_json} || [];
    const mapEl = document.getElementById("rp-commercial-map");
    let map = null;
    let markers = [];
    let infoWindow = null;
    function ensureMap(){
        if(!mapEl || !googleKeyAvailable || typeof google === "undefined" || !google.maps) return false;
        mapEl.style.minHeight = mapEl.style.minHeight || "360px";
        mapEl.style.height = mapEl.style.height || "360px";
        if(!map){
            map = new google.maps.Map(mapEl, {
                center:{lat:39.5,lng:-8},
                zoom:7,
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                gestureHandling:'greedy',
                streetViewControl:false,
                fullscreenControl:true,
                mapTypeControl:false
            });
            infoWindow = new google.maps.InfoWindow();
        }
        return true;
    }
    const filterClient = document.getElementById("rp-commercial-filter-client");
    const filterProject = document.getElementById("rp-commercial-filter-project");
    const filterDistrict = document.getElementById("rp-commercial-filter-district");
    const filterCounty = document.getElementById("rp-commercial-filter-county");
    const filterCity = document.getElementById("rp-commercial-filter-city");
    const filterCategory = document.getElementById("rp-commercial-filter-category");
    const filterSubcategory = document.getElementById("rp-commercial-filter-subcategory");
    const searchQ = document.getElementById("rp-commercial-q");
    const pdvName = document.getElementById("rp-pdv-name");
    const pdvAddress = document.getElementById("rp-pdv-address");
    const pdvDistrict = document.getElementById("rp-pdv-district");
    const pdvCounty = document.getElementById("rp-pdv-county");
    const pdvCity = document.getElementById("rp-pdv-city");
    const pdvParish = document.getElementById("rp-pdv-parish");
    const pdvPostalCode = document.getElementById("rp-pdv-postal-code");
    const pdvCountry = document.getElementById("rp-pdv-country");
    const pdvPhone = document.getElementById("rp-pdv-phone");
    const pdvEmail = document.getElementById("rp-pdv-email");
    const pdvWebsite = document.getElementById("rp-pdv-website");
    const pdvExternalRef = document.getElementById("rp-pdv-external-ref");
    const pdvContactPerson = document.getElementById("rp-pdv-contact-person");
    const pdvPlaceId = document.getElementById("rp-pdv-place-id");
    const pdvSource = document.getElementById("rp-pdv-source");
    const pdvLat = document.getElementById("rp-pdv-lat");
    const pdvLng = document.getElementById("rp-pdv-lng");
    const pdvCategory = document.getElementById("rp-pdv-category");
    const pdvSubcategory = document.getElementById("rp-pdv-subcategory");
    const pdvClientId = document.getElementById("rp-pdv-client-id");
    const pdvProjectId = document.getElementById("rp-pdv-project-id");
    const commercialForm = document.getElementById("rp-commercial-filter-form");
    const tableBody = document.getElementById("rp-commercial-table-body");
    const googleResultsBody = document.getElementById("rp-google-results-body");
    const statTotal = document.getElementById("rp-commercial-stat-total");
    const statCoords = document.getElementById("rp-commercial-stat-coords");
    const statGoogle = document.getElementById("rp-commercial-stat-google");
    const statValidated = document.getElementById("rp-commercial-stat-validated");
    const resultsSummary = document.getElementById("rp-commercial-results-summary");
    const hiddenDiv = document.createElement("div");
    hiddenDiv.style.display = "none";
    document.body.appendChild(hiddenDiv);
    let gMap = null, geocoder = null, placesService = null;
    function getPlaceDetails(placeId){
        return new Promise(function(resolve){
            if (!placeId || !setupGoogle() || !placesService) { resolve(null); return; }
            placesService.getDetails({placeId: placeId, fields:["name","formatted_address","geometry","address_components","place_id","formatted_phone_number","international_phone_number"]}, function(place, status){
                if (status === google.maps.places.PlacesServiceStatus.OK) resolve(place || null);
                else resolve(null);
            });
        });
    }

    function uniq(items){ return Array.from(new Set((items || []).filter(Boolean))).sort((a,b) => String(a).localeCompare(String(b), 'pt')); }
    function setupGoogle(){
        if (!googleKeyAvailable || typeof google === "undefined" || !google.maps) return false;
        if (!gMap) {
            gMap = new google.maps.Map(hiddenDiv, {center:{lat:38.7223,lng:-9.1393}, zoom:12});
            geocoder = new google.maps.Geocoder();
            placesService = new google.maps.places.PlacesService(gMap);
        }
        return true;
    }
    function parseAddress(place){
        const out = {district:"", county:"", city:"", parish:"", postal_code:"", country:"Portugal"};
        const comps = (place && place.address_components) || [];
        comps.forEach(function(comp){
            const types = comp.types || [];
            if (types.includes("administrative_area_level_1")) out.district = comp.long_name || "";
            if (types.includes("administrative_area_level_2")) out.county = comp.long_name || "";
            if (types.includes("locality")) out.city = comp.long_name || out.city;
            if (!out.city && types.includes("postal_town")) out.city = comp.long_name || out.city;
            if (!out.city && types.includes("administrative_area_level_3")) out.city = comp.long_name || out.city;
            if (types.includes("administrative_area_level_4") || types.includes("sublocality") || types.includes("sublocality_level_1")) out.parish = comp.long_name || out.parish;
            if (types.includes("postal_code")) out.postal_code = comp.long_name || out.postal_code;
            if (types.includes("country")) out.country = comp.long_name || out.country;
        });
        return out;
    }
    function isSelect(el){ return !!el && String(el.tagName || '').toUpperCase() === 'SELECT'; }
    function repopulateSelect(selectEl, items, selectedValue, placeholder){
        if (!isSelect(selectEl)) return;
        const current = selectedValue || "";
        const options = ['<option value="">' + (placeholder || '--') + '</option>'];
        uniq(items).forEach(function(v){
            const safe = String(v).replace(/"/g, '&quot;');
            options.push('<option value="' + safe + '">' + String(v) + '</option>');
        });
        selectEl.innerHTML = options.join('');
        if (current) {
            let found = Array.from(selectEl.options || []).find(function(o){ return o.value === current; });
            if (!found) {
                found = document.createElement('option');
                found.value = current;
                found.textContent = current;
                selectEl.appendChild(found);
            }
            selectEl.value = current;
        }
    }
    function setSelectValue(selectEl, value){
        if (!selectEl) return;
        if (!value) { selectEl.value = ''; return; }
        if (!isSelect(selectEl)) { selectEl.value = value; return; }
        let found = Array.from(selectEl.options || []).find(function(o){ return o.value === value; });
        if (!found) {
            found = document.createElement('option');
            found.value = value;
            found.textContent = value;
            selectEl.appendChild(found);
        }
        selectEl.value = value;
    }
    function refreshSubcategories(){
        if (!isSelect(filterSubcategory)) return;
        const pid = parseInt((filterCategory && filterCategory.value) || '0', 10) || 0;
        const selected = filterSubcategory.dataset.selected || filterSubcategory.value || '';
        const options = ['<option value="">Subcategoria</option>'];
        const seen = {};
        (subcategoriesByParent[String(pid)] || subcategoriesByParent[pid] || []).forEach(function(item){
            const name = String((item && item.name) || '').trim();
            const key = name.toLowerCase();
            if (!name || seen[key]) return;
            seen[key] = 1;
            options.push('<option value="'+String(item.id)+'">'+name+'</option>');
        });
        filterSubcategory.innerHTML = options.join('');
        if (selected) filterSubcategory.value = selected;
    }
    function refreshPdvSubcategories(){
        if (!isSelect(pdvSubcategory)) return;
        const pid = parseInt((pdvCategory && pdvCategory.value) || '0', 10) || 0;
        const selected = pdvSubcategory.dataset.selected || pdvSubcategory.value || '';
        if (!pid) { pdvSubcategory.innerHTML = '<option value="">--</option>'; return; }
        const options = ['<option value="">--</option>'];
        const seen = {};
        (subcategoriesByParent[String(pid)] || subcategoriesByParent[pid] || []).forEach(function(item){
            const name = String((item && item.name) || '').trim();
            const key = name.toLowerCase();
            if (!name || seen[key]) return;
            seen[key] = 1;
            options.push('<option value="'+String(item.id)+'">'+name+'</option>');
        });
        pdvSubcategory.innerHTML = options.join('');
        if (selected) pdvSubcategory.value = selected;
    }
    function syncProjectDropdown(){
        if (!filterProject || !filterClient) return;
        const client = filterClient.value;
        Array.from(filterProject.options || []).forEach(function(opt, idx){ if (idx === 0) return; opt.hidden = !!client && opt.dataset.client !== client; });
        if (filterProject.selectedOptions && filterProject.selectedOptions[0] && filterProject.selectedOptions[0].hidden) filterProject.value = '';
    }
    function syncGeoDropdowns(source){
        if (source === 'pdv') return;
        const districtValue = ((filterDistrict && filterDistrict.value) || '');
        const allCounties = uniq(Object.values(countiesByDistrict).flat());
        const allCities = uniq(Object.values(citiesByDistrict).flat());
        const countySelect = filterCounty;
        const citySelect = filterCity;
        if (isSelect(countySelect)) repopulateSelect(countySelect, districtValue ? (countiesByDistrict[districtValue] || []) : allCounties, countySelect ? (countySelect.dataset.selected || countySelect.value) : '', 'Concelho');
        if (isSelect(citySelect)) repopulateSelect(citySelect, districtValue ? (citiesByDistrict[districtValue] || []) : allCities, citySelect ? (citySelect.dataset.selected || citySelect.value) : '', 'Cidade');
        if (countySelect && countySelect.dataset) countySelect.dataset.selected = '';
        if (citySelect && citySelect.dataset) citySelect.dataset.selected = '';
    }
    function fillPdvForm(item){
        if (!item) return;
        if (pdvName) pdvName.value = item.name || pdvName.value || '';
        if (pdvAddress) pdvAddress.value = item.address || item.formatted_address || item.vicinity || pdvAddress.value || '';
        if (pdvDistrict) pdvDistrict.value = item.district || pdvDistrict.value || '';
        if (pdvCounty) pdvCounty.value = item.county || pdvCounty.value || '';
        if (pdvCity) pdvCity.value = item.city || pdvCity.value || '';
        if (pdvParish) pdvParish.value = item.parish || pdvParish.value || '';
        if (pdvPostalCode) pdvPostalCode.value = item.postal_code || pdvPostalCode.value || '';
        if (pdvCountry) pdvCountry.value = item.country || pdvCountry.value || 'Portugal';
        if (pdvPhone) pdvPhone.value = item.phone || item.formatted_phone_number || pdvPhone.value || '';
        if (pdvEmail) pdvEmail.value = item.email || pdvEmail.value || '';
        if (pdvWebsite) pdvWebsite.value = item.website || pdvWebsite.value || '';
        if (pdvExternalRef) pdvExternalRef.value = item.external_ref || pdvExternalRef.value || '';
        if (pdvContactPerson) pdvContactPerson.value = item.contact_person || item.name || pdvContactPerson.value || '';
        if (pdvPlaceId) pdvPlaceId.value = item.place_id || pdvPlaceId.value || '';
        if (pdvSource) pdvSource.value = item.source || pdvSource.value || 'manual';
        if (pdvLat && item.lat != null) pdvLat.value = String(item.lat);
        if (pdvLng && item.lng != null) pdvLng.value = String(item.lng);
        if (pdvCategory && item.category_id) pdvCategory.value = String(item.category_id);
        refreshPdvSubcategories();
    if (pdvSubcategory && pdvSubcategory.dataset && pdvSubcategory.dataset.selected) { pdvSubcategory.value = String(pdvSubcategory.dataset.selected || ''); }
        if (pdvSubcategory && item.subcategory_id) pdvSubcategory.value = String(item.subcategory_id);
        const lat = parseFloat(item.lat), lng = parseFloat(item.lng);
        if (!Number.isNaN(lat) && !Number.isNaN(lng) && ensureMap()) { map.setCenter({lat:lat, lng:lng}); map.setZoom(15); }
        document.getElementById('rp-commercial-pdv-form')?.scrollIntoView({behavior:'smooth', block:'start'});
    }
    function renderInternalTable(items){
        if (!tableBody) return;
        if (!items || !items.length) {
            tableBody.innerHTML = '<tr><td colspan="11">Sem resultados.</td></tr>';
            bindUseButtons();
            return;
        }
        tableBody.innerHTML = items.map(function(item){
            const payload = encodeURIComponent(JSON.stringify(item));
            return '<tr>'+
                '<td>'+(item.id || '')+'</td>'+
                '<td><strong>'+(item.name || '')+'</strong><br><span style="color:#6b7280">'+(item.address || '')+'</span></td>'+
                '<td>'+(item.category_name || '')+'</td>'+
                '<td>'+(item.subcategory_name || '')+'</td>'+
                '<td>'+(item.district || '')+'</td>'+
                '<td>'+(item.county || '')+'</td>'+
                '<td>'+(item.city || '')+'</td>'+
                '<td>'+(item.phone || '')+'</td>'+
                '<td>'+(item.source || '')+'</td>'+
                '<td>'+(parseInt(item.is_validated || 0, 10) === 1 ? 'Sim' : 'Não')+'</td>'+
                '<td><button type="button" class="button button-small rp-use-pdv" data-item="'+payload+'">Usar</button> '+
                '<a class="button button-small" href="'+adminBase+'&edit_id='+(item.id || '')+'">Editar</a> '+
                '<form method="post" style="display:inline" onsubmit="return confirm(\'Apagar este PDV da base comercial?\');">'+
                '<input type="hidden" name="routespro_commercial_delete_nonce" value="'+deleteNonce+'" />'+
                '<input type="hidden" name="action_type" value="delete_pdv" />'+
                '<input type="hidden" name="location_id" value="'+(item.id || '')+'" />'+
                '<button type="submit" class="button button-small">Apagar</button></form></td>'+
            '</tr>';
        }).join('');
        bindUseButtons();
    }
    function updateStatsUI(stats, shownCount){
        const totalVisible = parseInt((stats && stats.total_visible) || 0, 10) || 0;
        const withCoords = parseInt((stats && stats.with_coords) || 0, 10) || 0;
        const googleCount = parseInt((stats && stats.google_count) || 0, 10) || 0;
        const validatedCount = parseInt((stats && stats.validated_count) || 0, 10) || 0;
        if (statTotal) statTotal.textContent = String(totalVisible);
        if (statCoords) statCoords.textContent = String(withCoords);
        if (statGoogle) statGoogle.textContent = String(googleCount);
        if (statValidated) statValidated.textContent = String(validatedCount);
        if (resultsSummary) {
            const shown = typeof shownCount === 'number' ? shownCount : totalVisible;
            resultsSummary.textContent = shown >= totalVisible
                ? 'A mostrar ' + totalVisible + ' PDVs filtrados.'
                : 'A mostrar ' + shown + ' de ' + totalVisible + ' PDVs filtrados.';
        }
    }

    function renderGoogleResults(items){
        if (!googleResultsBody) return;
        if (!items || !items.length) {
            googleResultsBody.innerHTML = '<tr><td colspan="4">Sem resultados Google carregados.</td></tr>';
            bindUseButtons();
            return;
        }
        googleResultsBody.innerHTML = items.map(function(item){
            const payload = encodeURIComponent(JSON.stringify(item));
            return '<tr>'+
                '<td><strong>'+(item.name || '')+'</strong></td>'+
                '<td>'+(item.address || item.vicinity || '')+'</td>'+
                '<td>'+([item.city, item.county, item.district].filter(Boolean).join(' / '))+'</td>'+
                '<td><button type="button" class="button button-small rp-use-pdv" data-item="'+payload+'">Usar</button></td>'+
            '</tr>';
        }).join('');
        bindUseButtons();
    }
    function bindUseButtons(){
        document.querySelectorAll('.rp-use-pdv').forEach(function(btn){
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function(){
                try { fillPdvForm(JSON.parse(decodeURIComponent(btn.dataset.item || '{}'))); } catch(e) {}
            });
        });
    }
    function bindPopupButtons(){
        document.querySelectorAll('.rp-popup-use').forEach(function(btn){
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function(){
                try { fillPdvForm(JSON.parse(decodeURIComponent(btn.dataset.item || '{}'))); } catch(e) {}
            });
        });
    }
    function bindAutocomplete(input, onPick){
        if (!input || !setupGoogle() || !google.maps.places || !google.maps.places.Autocomplete) return false;
        const ac = new google.maps.places.Autocomplete(input, {fields:["formatted_address","geometry","name","address_components","place_id"]});
        ac.addListener("place_changed", function(){
            const place = ac.getPlace();
            const parsed = parseAddress(place);
            onPick(place, parsed);
        });
        return true;
    }
    async function initGoogleFeatures(){
        if (!googleKeyAvailable) return;
        for (let i = 0; i < 90; i++) {
            if (bindAutocomplete(searchQ, function(place, parsed){
                searchQ.value = place.formatted_address || place.name || searchQ.value || "";
                if (parsed.district) { setSelectValue(filterDistrict, parsed.district); syncGeoDropdowns('filter'); }
                if (parsed.county) setSelectValue(filterCounty, parsed.county);
                if (parsed.city) setSelectValue(filterCity, parsed.city);
                if (place.geometry && place.geometry.location && ensureMap()) { map.setCenter({lat:place.geometry.location.lat(), lng:place.geometry.location.lng()}); map.setZoom(13); }
            })) {
                bindAutocomplete(pdvAddress, async function(place, parsed){
                    const details = place && place.place_id ? await getPlaceDetails(place.place_id) : null;
                    fillPdvForm({
                        name: (details && details.name) || place.name || '',
                        address: (details && details.formatted_address) || place.formatted_address || '',
                        district: parsed.district,
                        county: parsed.county,
                        city: parsed.city,
                        parish: parsed.parish,
                        postal_code: parsed.postal_code,
                        country: parsed.country,
                        lat: place.geometry && place.geometry.location ? place.geometry.location.lat() : null,
                        lng: place.geometry && place.geometry.location ? place.geometry.location.lng() : null,
                        place_id: place.place_id || '',
                        phone: (details && (details.international_phone_number || details.formatted_phone_number)) || '',
                        website: (details && details.website) || '',
                        contact_person: (details && details.name) || place.name || '',
                        category_id: parseInt((filterCategory && filterCategory.value) || '0', 10) || 0,
                        subcategory_id: parseInt((filterSubcategory && filterSubcategory.value) || '0', 10) || 0,
                        source: 'google'
                    });
                });
                return;
            }
            await new Promise(function(resolve){ window.setTimeout(resolve, 300); });
        }
    }
    function currentZoneQuery(){
        return [filterCity && filterCity.value, filterCounty && filterCounty.value, filterDistrict && filterDistrict.value, "Portugal"].filter(Boolean).join(", ") || [searchQ && searchQ.value, "Portugal"].filter(Boolean).join(", ");
    }
    function currentKeyword(){
        const subcategoryText = filterSubcategory && filterSubcategory.selectedIndex >= 0 ? (filterSubcategory.options[filterSubcategory.selectedIndex].text || "") : "";
        const categoryText = filterCategory && filterCategory.selectedIndex >= 0 ? (filterCategory.options[filterCategory.selectedIndex].text || "") : "";
        const qText = ((searchQ && searchQ.value) || "").trim();
        const baseText = subcategoryText && subcategoryText !== "Subcategoria" ? subcategoryText : ((categoryText && categoryText !== "Categoria") ? categoryText : "");
        if (baseText && qText) return baseText + " " + qText;
        return baseText || qText || "estabelecimentos comerciais";
    }
    function googleTypeFromText(label){
        const text = String(label || "").toLowerCase();
        const mapType = [
            ["restaurant", ["restaurante","snack-bar","snack bar","pizzaria","hamburgueria","take away"]],
            ["cafe", ["cafe","café","coffee","pastelaria"]],
            ["bar", ["bar","pub","cocktail"]],
            ["bakery", ["padaria","pastelaria"]],
            ["lodging", ["hotel","hostel","alojamento","resort"]],
            ["store", ["loja","retalho","gourmet","conveniência","conveniencia","boutique"]],
            ["supermarket", ["supermercado","cash & carry","cash and carry","mercearia","hipermercado"]],
            ["liquor_store", ["garrafeira","wine"]],
            ["meal_takeaway", ["take away","takeaway"]]
        ];
        for (const pair of mapType) if (pair[1].some(function(term){ return text.includes(term); })) return pair[0];
        return null;
    }
    function geocodeAddress(address){
        return new Promise(function(resolve, reject){
            if (!setupGoogle() || !geocoder) return reject(new Error("Google Maps não carregado para geocoding."));
            geocoder.geocode({address: address, region:"pt"}, function(results, status){
                if (status !== "OK" || !results || !results.length) return reject(new Error("Sem coordenadas para a zona selecionada."));
                resolve(results[0]);
            });
        });
    }
    function wait(ms){ return new Promise(function(resolve){ window.setTimeout(resolve, ms); }); }
    function nearbySearchPage(request){
        return new Promise(function(resolve, reject){
            if (!setupGoogle() || !placesService) return reject(new Error("Google Places não carregado."));
            placesService.nearbySearch(request, function(results, status, pagination){
                if (status !== google.maps.places.PlacesServiceStatus.OK && status !== google.maps.places.PlacesServiceStatus.ZERO_RESULTS) return reject(new Error("Google Places devolveu: " + status));
                resolve({results: results || [], pagination: pagination || null, status: status});
            });
        });
    }
    function textSearchPage(request){
        return new Promise(function(resolve, reject){
            if (!setupGoogle() || !placesService) return reject(new Error("Google Places não carregado."));
            placesService.textSearch(request, function(results, status, pagination){
                if (status !== google.maps.places.PlacesServiceStatus.OK && status !== google.maps.places.PlacesServiceStatus.ZERO_RESULTS) return reject(new Error("Google Places devolveu: " + status));
                resolve({results: results || [], pagination: pagination || null, status: status});
            });
        });
    }
    async function collectPaged(method, request){
        const all = [];
        const first = await method(request);
        all.push(...(first.results || []));
        let pagination = first.pagination;
        for (let page = 0; page < 2; page++) {
            if (!pagination || !pagination.hasNextPage) break;
            await wait(2200);
            const next = await new Promise(function(resolve){
                const previousCallback = pagination.nextPage;
                pagination.nextPage();
                window.setTimeout(function(){ resolve({results: [], pagination: null}); }, 2600);
            }).catch(function(){ return {results:[], pagination:null}; });
            if (next.results && next.results.length) all.push(...next.results);
            pagination = next.pagination;
        }
        return all;
    }
    function pagedSearch(methodName, request){
        return new Promise(function(resolve, reject){
            const out = [];
            const handler = function(results, status, pagination){
                if (status !== google.maps.places.PlacesServiceStatus.OK && status !== google.maps.places.PlacesServiceStatus.ZERO_RESULTS) return reject(new Error("Google Places devolveu: " + status));
                out.push(...(results || []));
                if (pagination && pagination.hasNextPage && out.length < 60) {
                    window.setTimeout(function(){ pagination.nextPage(); }, 2200);
                    return;
                }
                resolve(out);
            };
            if (methodName === 'textSearch') placesService.textSearch(request, handler);
            else placesService.nearbySearch(request, handler);
        });
    }
    function getPlaceDetails(placeId){
        return new Promise(function(resolve){
            if (!placeId || !setupGoogle() || !placesService) return resolve({});
            placesService.getDetails({placeId: placeId, fields:["formatted_phone_number","international_phone_number","website","name","formatted_address","address_components","geometry","place_id"]}, function(result, status){
                if (status !== google.maps.places.PlacesServiceStatus.OK || !result) return resolve({});
                resolve(result);
            });
        });
    }
    function clearMarkers(){
        markers.forEach(function(marker){ try{ marker.setMap(null); }catch(_){ } });
        markers = [];
    }
    function fitMarkerPoints(points){
        if (!ensureMap()) return;
        if (!points || !points.length) {
            map.setCenter({lat:39.5,lng:-8});
            map.setZoom(7);
            return;
        }
        if (points.length === 1) {
            map.setCenter(points[0]);
            map.setZoom(14);
            return;
        }
        const bounds = new google.maps.LatLngBounds();
        points.forEach(function(pt){ bounds.extend(pt); });
        map.fitBounds(bounds, 40);
    }
    function addMarkerFromItem(item, isGoogle){
        if (!ensureMap()) return null;
        const lat = item.lat != null ? parseFloat(item.lat) : null;
        const lng = item.lng != null ? parseFloat(item.lng) : null;
        if (lat === null || lng === null || Number.isNaN(lat) || Number.isNaN(lng)) return null;
        const marker = new google.maps.Marker({
            map: map,
            position: {lat:lat, lng:lng},
            title: item.name || item.address || 'PDV',
            icon: isGoogle ? {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 7,
                fillColor: '#f97316',
                fillOpacity: 0.95,
                strokeColor: '#c2410c',
                strokeWeight: 2
            } : {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 7,
                fillColor: '#2563eb',
                fillOpacity: 0.95,
                strokeColor: '#1d4ed8',
                strokeWeight: 2
            }
        });
        marker.addListener('click', function(){
            fillPdvForm(item);
            infoWindow.setContent('<div style="max-width:280px"><strong>' + (item.name || '') + '</strong><br>' + (item.address || item.vicinity || '') + '<br><button type="button" class="button button-small rp-popup-use" data-item="' + encodeURIComponent(JSON.stringify(item)) + '">Usar no formulário</button>' + (isGoogle ? '<br><em>Resultado Google</em>' : '') + '</div>');
            infoWindow.open({anchor: marker, map: map});
            window.setTimeout(bindPopupButtons, 40);
        });
        markers.push(marker);
        return {lat:lat, lng:lng};
    }
    async function loadInternal(){
        clearMarkers();
        const params = new URLSearchParams();
        params.set("page", "1");
        params.set("per_page", "250");
        if(filterDistrict && filterDistrict.value) params.set("district", filterDistrict.value);
        if(filterCounty && filterCounty.value) params.set("county", filterCounty.value);
        if(filterCity && filterCity.value) params.set("city", filterCity.value);
        if(filterCategory && filterCategory.value) params.set("category_id", filterCategory.value);
        if(filterSubcategory && filterSubcategory.value) params.set("subcategory_id", filterSubcategory.value);
        if(searchQ && searchQ.value) params.set("q", searchQ.value);
        if(filterClient && filterClient.value) params.set("client_id", filterClient.value);
        if(filterProject && filterProject.value) params.set("project_id", filterProject.value);
        const res = await fetch(api + "commercial-search?" + params.toString(), {credentials:"same-origin", headers:{"X-WP-Nonce":nonce}});
        if(!res.ok){ renderInternalTable([]); updateStatsUI({total_visible:0,with_coords:0,google_count:0,validated_count:0}, 0); return; }
        const json = await res.json();
        const items = Array.isArray(json.items) ? json.items : [];
        renderInternalTable(items);
        updateStatsUI(json.stats || {total_visible: json.total || items.length, with_coords: items.filter(function(x){ return x.lat != null && x.lng != null; }).length, google_count: items.filter(function(x){ return (x.source || '') === 'google'; }).length, validated_count: items.filter(function(x){ return parseInt(x.is_validated || 0, 10) === 1; }).length}, items.length);
        const pts = [];
        items.forEach(function(item){ const pt = addMarkerFromItem(item, false); if (pt) pts.push(pt); });
        fitMarkerPoints(pts);
    }
    async function discoverGoogle(){
        if (!setupGoogle()) { alert("Google Maps Places não está disponível nas settings do plugin."); return; }
        const zone = currentZoneQuery();
        if (!zone) { alert("Escolhe pelo menos uma zona geográfica antes de procurar PDVs."); return; }
        try {
            const geo = await geocodeAddress(zone);
            const center = geo.geometry.location;
            const label = currentKeyword();
            const googleType = googleTypeFromText(label);
            const radius = filterCity && filterCity.value ? 15000 : (filterCounty && filterCounty.value ? 35000 : 80000);
            const textRequest = {query: label + " em " + zone, location: center, radius: radius};
            const nearbyRequest = {location: center, radius: radius, keyword: label};
            if (googleType) nearbyRequest.type = googleType;
            const textResults = await pagedSearch('textSearch', textRequest).catch(function(){ return []; });
            const nearbyResults = await pagedSearch('nearbySearch', nearbyRequest).catch(function(){ return []; });
            const merged = new Map();
            [].concat(textResults || [], nearbyResults || []).forEach(function(item){
                const key = item.place_id || ((item.name || '') + '|' + (item.formatted_address || item.vicinity || ''));
                if (!merged.has(key)) merged.set(key, item);
            });
            const googleItems = [];
            for (const item of Array.from(merged.values()).slice(0, 60)) {
                const details = await getPlaceDetails(item.place_id);
                const parsed = parseAddress(details.address_components ? details : item);
                const lat = details.geometry && details.geometry.location ? details.geometry.location.lat() : (item.geometry && item.geometry.location ? item.geometry.location.lat() : null);
                const lng = details.geometry && details.geometry.location ? details.geometry.location.lng() : (item.geometry && item.geometry.location ? item.geometry.location.lng() : null);
                googleItems.push({
                    name: details.name || item.name || '',
                    address: details.formatted_address || item.formatted_address || item.vicinity || '',
                    district: parsed.district || (filterDistrict && filterDistrict.value) || '',
                    county: parsed.county || (filterCounty && filterCounty.value) || '',
                    city: parsed.city || (filterCity && filterCity.value) || '',
                    parish: parsed.parish || '',
                    postal_code: parsed.postal_code || '',
                    country: parsed.country || 'Portugal',
                    lat: lat,
                    lng: lng,
                    place_id: item.place_id || details.place_id || '',
                    phone: details.international_phone_number || details.formatted_phone_number || item.formatted_phone_number || '',
                    email: '',
                    website: details.website || '',
                    contact_person: (details.name || item.name || ''),
                    source: 'google'
                });
            }
            const deduped = Array.from(new Map(googleItems.map(function(item){ return [item.place_id || ((item.name || '') + '|' + (item.address || '')), item]; })).values());
            const pts = [];
            deduped.forEach(function(item){ const pt = addMarkerFromItem(item, true); if (pt) pts.push(pt); });
            renderGoogleResults(deduped);
            if (!deduped.length) { alert("A Google não devolveu resultados para esta zona e categoria."); return; }
            fitMarkerPoints(pts);
        } catch(err) {
            alert(err && err.message ? err.message : "A descoberta Google falhou.");
        }
    }

    async function triggerFilter(){
        try {
            const btn = commercialForm ? commercialForm.querySelector('button.button-primary') : null;
            if (btn) { btn.disabled = true; btn.dataset.originalText = btn.dataset.originalText || btn.textContent; btn.textContent = 'A filtrar...'; }
            await loadInternal();
        } finally {
            const btn = commercialForm ? commercialForm.querySelector('button.button-primary') : null;
            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.originalText || 'Filtrar'; }
        }
    }
    function syncPdvLinkFields(){
        if (pdvClientId) pdvClientId.value = (filterClient && filterClient.value) || '';
        if (pdvProjectId) pdvProjectId.value = (filterProject && filterProject.value) || '';
    }
    filterClient?.addEventListener('change', function(){ syncProjectDropdown(); syncPdvLinkFields(); triggerFilter(); });
    filterProject?.addEventListener('change', function(){ syncPdvLinkFields(); triggerFilter(); });
    filterDistrict?.addEventListener('change', function(){ syncGeoDropdowns('filter'); triggerFilter(); });
    filterCounty?.addEventListener('change', triggerFilter);
    filterCity?.addEventListener('change', triggerFilter);
    filterCategory?.addEventListener('change', function(){ if (filterSubcategory) { filterSubcategory.dataset.selected = ''; filterSubcategory.value = ''; } refreshSubcategories(); triggerFilter(); });
    filterSubcategory?.addEventListener('change', triggerFilter);
    pdvCategory?.addEventListener('change', refreshPdvSubcategories);
    searchQ?.addEventListener('input', function(){ window.clearTimeout(searchQ._rpTimer); searchQ._rpTimer = window.setTimeout(triggerFilter, 250); });
    commercialForm?.addEventListener('submit', async function(ev){ ev.preventDefault(); await triggerFilter(); await discoverGoogle(); });
    syncProjectDropdown();
    syncPdvLinkFields();
    syncGeoDropdowns('filter');
    refreshSubcategories();
    refreshPdvSubcategories();
    bindUseButtons();
    renderGoogleResults([]);
    document.getElementById("rp-commercial-refresh")?.addEventListener("click", loadInternal);
    document.getElementById("rp-google-discovery")?.addEventListener("click", discoverGoogle);
    [searchQ, pdvAddress].forEach(function(el){
        el?.addEventListener("focus", function(){ window.setTimeout(initGoogleFeatures, 50); }, {once:false});
    });
    initGoogleFeatures();
    if (Array.isArray(initialRows) && initialRows.length) { renderInternalTable(initialRows); const pts=[]; initialRows.forEach(function(item){ const pt=addMarkerFromItem(item,false); if(pt) pts.push(pt); }); fitMarkerPoints(pts); }
    loadInternal();
});
</script>
HTML;
    }
}
