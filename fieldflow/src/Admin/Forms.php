<?php
namespace RoutesPro\Admin;
use RoutesPro\Forms\Forms as FormsModule;
if (!defined('ABSPATH')) exit;
class Forms {
    public static function register_hooks() {
        add_action('admin_post_routespro_save_form', [self::class, 'handle_save']);
        add_action('admin_post_routespro_delete_form', [self::class, 'handle_delete']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }
    public static function enqueue_assets($hook) {
        if (empty($_GET['page']) || !in_array($_GET['page'], ['routespro-forms','routespro-form-edit'], true)) return;
        wp_enqueue_style('routespro-form-builder', ROUTESPRO_URL . 'assets/routespro-form-builder.css', [], ROUTESPRO_VERSION); wp_enqueue_script('jquery-ui-sortable'); wp_enqueue_media(); wp_enqueue_script('routespro-form-builder', ROUTESPRO_URL . 'assets/routespro-form-builder.js', ['jquery','jquery-ui-sortable'], ROUTESPRO_VERSION, true);
    }
    public static function render() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.'); $rows = FormsModule::list_forms(); echo '<div class="wrap">'; Branding::render_header('Formulários', 'Fase 1, motor interno de formulários dinâmicos já integrado no FieldFlow.'); echo '<p><a href="'.esc_url(admin_url('admin.php?page=routespro-form-edit')).'" class="button button-primary">Novo formulário</a></p>'; echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Título</th><th>Estado</th><th>Shortcode</th><th>Atualizado</th><th>Ações</th></tr></thead><tbody>'; if(!$rows){ echo '<tr><td colspan="6">Ainda não existem formulários.</td></tr>'; } else { foreach($rows as $row){ $edit=admin_url('admin.php?page=routespro-form-edit&id='.(int)$row['id']); $del=wp_nonce_url(admin_url('admin-post.php?action=routespro_delete_form&id='.(int)$row['id']), 'routespro_delete_form_'.(int)$row['id']); echo '<tr><td>'.(int)$row['id'].'</td><td><strong><a href="'.esc_url($edit).'">'.esc_html($row['title']).'</a></strong></td><td>'.esc_html($row['status']).'</td><td><code>[fieldflow_form id="'.(int)$row['id'].'"]</code></td><td>'.esc_html($row['updated_at']).'</td><td><a class="button button-small" href="'.esc_url($edit).'">Editar</a> <a class="button button-small" href="'.esc_url($del).'" onclick="return confirm(\'Apagar formulário?\')">Apagar</a></td></tr>'; } } echo '</tbody></table></div>';
    }
    public static function render_edit() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.'); $id = absint($_GET['id'] ?? 0); $form = $id ? FormsModule::get_form($id) : null; $schema = FormsModule::decode_schema($form['schema_json'] ?? ''); $theme = FormsModule::decode_json_array($form['theme_json'] ?? ''); $theme = array_merge(['primary'=>'#2271b1','primary_hover'=>'#135e96','radius'=>12], $theme); echo '<div class="wrap">'; Branding::render_header($id ? 'Editar formulário' : 'Novo formulário', 'Builder interno, preparado para a fase 1 sem mexer no core das rotas.'); if(isset($_GET['saved'])) echo '<div class="notice notice-success"><p>Formulário guardado.</p></div>'; echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="routespro_save_form"><input type="hidden" name="id" value="'.(int)$id.'">'; wp_nonce_field('routespro_save_form', 'routespro_save_form_nonce'); echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="routespro_form_title">Título</label></th><td><input type="text" class="regular-text" id="routespro_form_title" name="title" value="'.esc_attr($form['title'] ?? '').'" required></td></tr><tr><th scope="row"><label for="routespro_form_status">Estado</label></th><td><select name="status" id="routespro_form_status"><option value="active"'.selected(($form['status'] ?? 'active'), 'active', false).'>Activo</option><option value="inactive"'.selected(($form['status'] ?? 'active'), 'inactive', false).'>Inactivo</option></select></td></tr><tr><th scope="row">Tema</th><td><label>Cor primária <input type="color" name="theme_primary" value="'.esc_attr($theme['primary']).'"></label> <label style="margin-left:12px">Hover <input type="color" name="theme_primary_hover" value="'.esc_attr($theme['primary_hover']).'"></label> <label style="margin-left:12px">Radius <input type="number" min="0" max="30" name="theme_radius" value="'.esc_attr((string)$theme['radius']).'" style="width:80px"></label></td></tr></tbody></table>';
        $encoded = wp_json_encode($schema, JSON_UNESCAPED_UNICODE); echo '<div id="routespro-form-builder-admin" class="twt-tcrm-admin twt-tcrm-form-builder"><div class="twt-fb-meta"><div class="twt-fb-row"><label><strong>Título interno do schema</strong></label><input type="text" class="twt-fb-input" data-fb-meta="title" value="'.esc_attr($schema['meta']['title'] ?? '').'" placeholder="Ex, Report Loja"></div><div class="twt-fb-row"><label><strong>Subtítulo</strong></label><input type="text" class="twt-fb-input" data-fb-meta="subtitle" value="'.esc_attr($schema['meta']['subtitle'] ?? '').'" placeholder="Ex, Semana 3, Norte"></div></div><hr style="margin:14px 0;"><div class="twt-fb-toolbar"><button type="button" class="button button-primary" id="twt-fb-add">Adicionar pergunta</button><span class="twt-fb-hint">Arrasta para reordenar. Guarda para aplicar.</span></div><div class="twt-fb-layout"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;"><div><strong>Layout</strong><div class="twt-fb-small">Configura steps, progresso e largura por campo.</div></div><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;"><label style="display:inline-flex;align-items:center;gap:8px;"><input type="checkbox" data-fb-layout-mode> <span>Wizard, Steps</span></label><label style="display:inline-flex;align-items:center;gap:8px;"><input type="checkbox" data-fb-layout-progress> <span>Mostrar progresso</span></label><button type="button" class="button" data-fb-add-step>Adicionar step</button></div></div><div class="twt-fb-steps"></div></div><div id="twt-fb-list" class="twt-fb-list"></div><input type="hidden" id="twt_form_schema_json" name="schema_json" value="'.esc_attr($encoded).'"><script type="text/template" id="twt-fb-item-tpl"><div class="twt-fb-item" data-id="{{id}}"><div class="twt-fb-head"><span class="twt-fb-drag" title="Arrastar">::</span><strong class="twt-fb-label">{{label}}</strong><span class="twt-fb-type">{{type}}</span><button type="button" class="button-link-delete twt-fb-del">Apagar</button></div><div class="twt-fb-body"><div class="twt-fb-grid"><div class="twt-fb-row"><label>Label</label><input type="text" data-fb="label" value="{{label}}"></div><div class="twt-fb-row"><label>Key</label><input type="text" data-fb="key" value="{{key}}" placeholder="auto, se vazio"><div class="twt-fb-small">Sem espaços, minúsculas.</div></div><div class="twt-fb-row"><label>Tipo</label><select data-fb="type" class="twt-fb-type-select"><option value="text">Texto</option><option value="textarea">Texto longo</option><option value="number">Número</option><option value="currency">Euro</option><option value="percent">Percentagem</option><option value="date">Data</option><option value="time">Hora</option><option value="checkbox">Checkbox</option><option value="select">Selecção</option><option value="radio">Radio</option><option value="image_upload">Upload imagem</option><option value="file_upload">Upload ficheiro</option></select></div><div class="twt-fb-preview" data-fb-preview></div><div class="twt-fb-row"><label>Obrigatório</label><label class="twt-fb-check"><input type="checkbox" data-fb="required" {{required}}><span>Sim</span></label></div></div><div class="twt-fb-row"><label>Ajuda</label><input type="text" data-fb="help_text" value="{{help_text}}"></div><div class="twt-fb-row twt-fb-options"><label>Opções, uma por linha</label><textarea data-fb="options" rows="4">{{options_text}}</textarea></div><div class="twt-fb-grid twt-fb-grid-3"><div class="twt-fb-row"><label>Mínimo</label><input type="number" step="0.01" data-fb="min" value="{{min}}"></div><div class="twt-fb-row"><label>Máximo</label><input type="number" step="0.01" data-fb="max" value="{{max}}"></div><div class="twt-fb-row"><label>Unidade</label><input type="text" data-fb="unit" value="{{unit}}"></div></div></div></div></script></div><h2 style="margin-top:24px">JSON avançado</h2><p class="description">Opcionalmente podes editar o schema diretamente. Se o deixares inválido, o builder vai recuperar um schema vazio.</p><textarea name="schema_json_raw" style="width:100%;min-height:240px;font-family:ui-monospace,monospace">'.esc_textarea(wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)).'</textarea>';
        submit_button($id ? 'Guardar formulário' : 'Criar formulário'); echo '</form></div>';
    }
    public static function handle_save() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        check_admin_referer('routespro_save_form', 'routespro_save_form_nonce');
        global $wpdb;

        $id = absint($_POST['id'] ?? 0);
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'active'));
        if (!in_array($status,['active','inactive'],true)) $status='active';

        $schema_hidden = trim((string) wp_unslash($_POST['schema_json'] ?? ''));
        $schema_raw = trim((string) wp_unslash($_POST['schema_json_raw'] ?? ''));

        $decoded_hidden = json_decode($schema_hidden, true);
        $decoded_raw = json_decode($schema_raw, true);

        // O builder visual manda em schema_json. O JSON avançado só entra como fallback.
        if (is_array($decoded_hidden)) {
            $schema_source = $decoded_hidden;
        } elseif (is_array($decoded_raw)) {
            $schema_source = $decoded_raw;
        } else {
            $schema_source = FormsModule::default_schema();
        }

        $schema = FormsModule::decode_schema(wp_json_encode($schema_source, JSON_UNESCAPED_UNICODE));

        $data = [
            'title'=>$title ?: 'Novo formulário',
            'status'=>$status,
            'schema_json'=>wp_json_encode($schema, JSON_UNESCAPED_UNICODE),
            'settings_json'=>wp_json_encode([], JSON_UNESCAPED_UNICODE),
            'theme_json'=>wp_json_encode([
                'primary'=>sanitize_hex_color(wp_unslash($_POST['theme_primary'] ?? '#2271b1')) ?: '#2271b1',
                'primary_hover'=>sanitize_hex_color(wp_unslash($_POST['theme_primary_hover'] ?? '#135e96')) ?: '#135e96',
                'radius'=>max(0,min(30,(int)($_POST['theme_radius'] ?? 12)))
            ], JSON_UNESCAPED_UNICODE),
            'updated_at'=>current_time('mysql')
        ];

        if($id){
            $wpdb->update(FormsModule::table(), $data, ['id'=>$id], ['%s','%s','%s','%s','%s','%s'], ['%d']);
        } else {
            $data['created_at']=current_time('mysql');
            $wpdb->insert(FormsModule::table(), $data, ['%s','%s','%s','%s','%s','%s','%s']);
            $id=(int)$wpdb->insert_id;
        }

        wp_safe_redirect(admin_url('admin.php?page=routespro-form-edit&id='.$id.'&saved=1'));
        exit;
    }
    public static function handle_delete() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.'); $id = absint($_GET['id'] ?? 0); check_admin_referer('routespro_delete_form_'.$id); global $wpdb; $wpdb->delete(FormsModule::table(), ['id'=>$id], ['%d']); wp_safe_redirect(admin_url('admin.php?page=routespro-forms')); exit;
    }
}
