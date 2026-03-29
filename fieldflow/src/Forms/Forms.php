<?php
namespace RoutesPro\Forms;
if (!defined('ABSPATH')) exit;
class Forms {
    const ACTION_SUBMIT = 'routespro_submit_form';
    const NONCE_FIELD = 'routespro_form_nonce';
    const NONCE_ACTION = 'routespro_form_submit';
    public static function boot() {
        add_shortcode('fieldflow_form', [self::class, 'shortcode_form']);
        add_shortcode('fieldflow_route_form', [self::class, 'shortcode_route_form']);
        // aliases legados
        add_shortcode('routespro_form', [self::class, 'shortcode_form']);
        add_shortcode('routespro_route_form', [self::class, 'shortcode_route_form']);
        add_action('admin_post_' . self::ACTION_SUBMIT, [self::class, 'handle_submit']);
        add_action('admin_post_nopriv_' . self::ACTION_SUBMIT, [self::class, 'handle_submit']);
        add_action('wp_enqueue_scripts', [FormRenderer::class, 'enqueue_assets']);
    }
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'routespro_forms'; }
    public static function table_submissions(): string { global $wpdb; return $wpdb->prefix . 'routespro_form_submissions'; }
    public static function table_answers(): string { global $wpdb; return $wpdb->prefix . 'routespro_form_submission_answers'; }
    public static function default_schema(): array {
        return ['meta'=>['title'=>'','subtitle'=>''],'layout'=>['mode'=>'single','show_progress'=>false,'steps'=>[],'field_layout'=>[]],'questions'=>[]];
    }
    public static function get_form(int $id): ?array { global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d', $id), ARRAY_A); return $row ?: null; }
    public static function list_forms(): array { global $wpdb; return $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC, id DESC', ARRAY_A) ?: []; }

    public static function shortcode_route_form($atts = []) {
        $atts = shortcode_atts(['client_id'=>0,'project_id'=>0,'route_id'=>0,'stop_id'=>0,'location_id'=>0,'fallback'=>'','show_title'=>1], $atts);

        $context = [
            'client_id' => absint($atts['client_id']),
            'project_id' => absint($atts['project_id']),
            'route_id' => absint($atts['route_id']),
            'stop_id' => absint($atts['stop_id']),
            'location_id' => absint($atts['location_id']),
        ];

        foreach (array_keys($context) as $field) {
            if (empty($context[$field]) && isset($_GET[$field])) {
                $context[$field] = absint(wp_unslash($_GET[$field]));
            }
        }

        $context = BindingResolver::get_context($context);
        $binding = BindingResolver::resolve($context);
        if (!$binding || empty($binding['form_id'])) {
            $fallback = trim((string) ($atts['fallback'] ?? ''));
            if ($fallback !== '') return do_shortcode($fallback);
            return '<p>Nenhum formulário activo para este contexto.</p>';
        }
        return self::render_form_with_context((int) $binding['form_id'], $context, $binding, [
            'show_title' => !empty($atts['show_title']),
        ]);
    }

    public static function shortcode_form($atts = []) {
        $atts = shortcode_atts(['id'=>0,'client_id'=>0,'project_id'=>0,'route_id'=>0,'stop_id'=>0,'location_id'=>0,'binding_id'=>0,'show_title'=>1], $atts);
        $context = [
            'client_id' => absint($atts['client_id']),
            'project_id' => absint($atts['project_id']),
            'route_id' => absint($atts['route_id']),
            'stop_id' => absint($atts['stop_id']),
            'location_id' => absint($atts['location_id']),
        ];
        $binding = !empty($atts['binding_id']) ? ['id' => absint($atts['binding_id'])] : null;
        return self::render_form_with_context(absint($atts['id']), $context, $binding, [
            'show_title' => !empty($atts['show_title']),
        ]);
    }

    public static function render_form_with_context(int $form_id, array $context = [], ?array $binding = null, array $opts = []) {
        if (!$form_id) return '<p>Formulário inválido.</p>';
        if (!is_user_logged_in()) return '<p>Precisas de login para submeter.</p>';
        $form = self::get_form($form_id);
        if (!$form || ($form['status'] ?? '') !== 'active') return '<p>Formulário não encontrado ou inativo.</p>';
        $schema = self::decode_schema($form['schema_json'] ?? '');
        if (empty($schema['questions'])) return '<p>O formulário não tem perguntas configuradas.</p>';
        $needs_multipart = FormRenderer::schema_needs_multipart($schema);
        $theme = self::decode_json_array($form['theme_json'] ?? '');
        $style_attr = FormRenderer::theme_style_attr($theme);
        $show_title = array_key_exists('show_title', $opts) ? !empty($opts['show_title']) : true;
        $hide_actions = !empty($opts['hide_actions']);
        $binding_id = (int) ($binding['id'] ?? 0);
        ob_start(); ?>
        <div class="routespro-form-wrap rp-form-ui"<?php echo $style_attr ? ' style="' . esc_attr($style_attr) . '"' : ''; ?>>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="routespro-dyn-form" data-routespro-form-id="<?php echo esc_attr($form_id); ?>"<?php echo $needs_multipart ? ' enctype="multipart/form-data"' : ''; ?>>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SUBMIT); ?>">
                <input type="hidden" name="routespro_form_id" value="<?php echo esc_attr($form_id); ?>">
                <input type="hidden" name="routespro_binding_id" value="<?php echo esc_attr($binding_id); ?>">
                <input type="hidden" name="routespro_client_id" value="<?php echo esc_attr((int) ($context['client_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_project_id" value="<?php echo esc_attr((int) ($context['project_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_route_id" value="<?php echo esc_attr((int) ($context['route_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_stop_id" value="<?php echo esc_attr((int) ($context['stop_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_location_id" value="<?php echo esc_attr((int) ($context['location_id'] ?? 0)); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="routespro_ajax" value="0">
                <?php if (isset($_GET['routespro_form_ok']) && wp_unslash($_GET['routespro_form_ok']) === '1'): ?><div class="routespro-form-notice success">Submissão gravada com sucesso.</div><?php endif; ?>
                <?php if (isset($_GET['routespro_form_err'])): ?><div class="routespro-form-notice error">Falha ao submeter: <?php echo esc_html(sanitize_text_field(wp_unslash($_GET['routespro_form_err']))); ?>.</div><?php endif; ?>
                <?php if ($show_title && (!empty($schema['meta']['title']) || !empty($schema['meta']['subtitle']))): ?>
                    <div class="routespro-form-head">
                        <?php if (!empty($schema['meta']['title'])): ?><h3><?php echo esc_html($schema['meta']['title']); ?></h3><?php endif; ?>
                        <?php if (!empty($schema['meta']['subtitle'])): ?><p><?php echo esc_html($schema['meta']['subtitle']); ?></p><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php echo FormRenderer::render_questions($schema); ?>
                <?php if (!$hide_actions): ?><div class="routespro-form-actions"><button type="submit" class="routespro-form-btn">Submeter</button></div><?php endif; ?>
            </form>
        </div>
        <?php return ob_get_clean();
    }

    public static function handle_submit() {
        $is_ajax = !empty($_POST['routespro_ajax']);
        if (!is_user_logged_in()) { self::finish_submit_error('sem_login', $is_ajax); }
        $nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) { self::finish_submit_error('nonce', $is_ajax); }
        $form_id = isset($_POST['routespro_form_id']) ? absint($_POST['routespro_form_id']) : 0;
        $form = self::get_form($form_id);
        if (!$form || ($form['status'] ?? '') !== 'active') { self::finish_submit_error('form', $is_ajax); }
        $schema = self::decode_schema($form['schema_json'] ?? '');
        if (empty($schema['questions'])) { self::finish_submit_error('schema', $is_ajax); }
        $answers = [];
        foreach ($schema['questions'] as $question) {
            if (!is_array($question)) continue; $key = sanitize_key($question['key'] ?? ''); if (!$key) continue;
            $type = sanitize_key($question['type'] ?? 'text'); $required = !empty($question['required']); $value = self::extract_value_from_request($key, $type);
            if ($required && self::is_empty_value($value, $type)) { self::finish_submit_error('campo_' . $key, $is_ajax); }
            if (self::is_empty_value($value, $type)) continue;
            $answers[$key] = ['type'=>$type,'value'=>$value,'label'=>sanitize_text_field($question['label'] ?? $key)];
        }
        $binding_id = isset($_POST['routespro_binding_id']) ? absint($_POST['routespro_binding_id']) : 0;
        $client_id = isset($_POST['routespro_client_id']) ? absint($_POST['routespro_client_id']) : 0;
        $project_id = isset($_POST['routespro_project_id']) ? absint($_POST['routespro_project_id']) : 0;
        $route_id = isset($_POST['routespro_route_id']) ? absint($_POST['routespro_route_id']) : 0;
        $route_stop_id = isset($_POST['routespro_stop_id']) ? absint($_POST['routespro_stop_id']) : 0;
        $location_id = isset($_POST['routespro_location_id']) ? absint($_POST['routespro_location_id']) : 0;
        global $wpdb;
        $wpdb->insert(self::table_submissions(), ['form_id'=>$form_id,'binding_id'=>$binding_id,'client_id'=>$client_id,'project_id'=>$project_id,'route_id'=>$route_id,'route_stop_id'=>$route_stop_id,'location_id'=>$location_id,'user_id'=>get_current_user_id(),'submitted_at'=>current_time('mysql'),'status'=>'submitted','meta_json'=>wp_json_encode(['source_url'=>esc_url_raw(wp_get_referer() ?: '')], JSON_UNESCAPED_UNICODE)], ['%d','%d','%d','%d','%d','%d','%d','%d','%s','%s','%s']);
        $submission_id = (int) $wpdb->insert_id;
        foreach ($answers as $key => $item) {
            $stored = self::prepare_answer_storage($item['value'], $item['type']);
            $wpdb->insert(self::table_answers(), ['submission_id'=>$submission_id,'question_key'=>$key,'question_label'=>$item['label'],'value_text'=>$stored['text'],'value_number'=>$stored['number'],'value_json'=>$stored['json'],'created_at'=>current_time('mysql')], ['%d','%s','%s','%s','%f','%s','%s']);
        }
        if ($is_ajax) {
            wp_send_json_success(['submission_id' => $submission_id, 'message' => 'Submissão gravada com sucesso.']);
        }
        wp_safe_redirect(self::redirect_back_with_success()); exit;
    }
    private static function finish_submit_error(string $code, bool $is_ajax): void {
        if ($is_ajax) {
            wp_send_json_error(['code' => $code, 'message' => $code], 400);
        }
        wp_safe_redirect(self::redirect_back_with_error($code));
        exit;
    }
    private static function extract_value_from_request(string $key, string $type) {
        if ($type === 'checkbox') return isset($_POST[$key]) ? 1 : 0;
        if ($type === 'image_upload' || $type === 'file_upload') return self::handle_upload($key);
        $raw = $_POST[$key] ?? null; if (is_array($raw)) return array_map('sanitize_text_field', wp_unslash($raw)); $raw = is_string($raw) ? wp_unslash($raw) : $raw;
        switch ($type) {
            case 'number': case 'currency': case 'percent': return ($raw === '' || $raw === null) ? '' : (float) str_replace(',', '.', (string) $raw);
            case 'textarea': return sanitize_textarea_field((string) $raw);
            default: return sanitize_text_field((string) $raw);
        }
    }
    private static function handle_upload(string $field_key) {
        if (empty($_FILES[$field_key]) || empty($_FILES[$field_key]['name'])) return ''; require_once ABSPATH . 'wp-admin/includes/file.php'; $uploaded = wp_handle_upload($_FILES[$field_key], ['test_form'=>false]); return !empty($uploaded['url']) ? esc_url_raw($uploaded['url']) : '';
    }
    private static function prepare_answer_storage($value, string $type): array {
        $text = null; $number = null; $json = null; if (is_array($value)) { $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE); $text = $json; } elseif (in_array($type, ['number','currency','percent'], true) && $value !== '') { $number = (float) $value; $text = (string) $value; } elseif ($type === 'checkbox') { $number = $value ? 1 : 0; $text = $value ? '1' : '0'; } else { $text = is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE); } return ['text'=>$text,'number'=>$number,'json'=>$json];
    }
    private static function is_empty_value($value, string $type): bool { if ($type === 'checkbox') return false; if (is_array($value)) return empty($value); return $value === '' || $value === null; }
    public static function decode_schema(string $json): array { $schema = self::decode_json_array($json); if (!$schema) $schema = self::default_schema(); if (empty($schema['meta']) || !is_array($schema['meta'])) $schema['meta'] = ['title'=>'','subtitle'=>'']; if (empty($schema['layout']) || !is_array($schema['layout'])) $schema['layout'] = self::default_schema()['layout']; if (empty($schema['questions']) || !is_array($schema['questions'])) $schema['questions'] = []; return $schema; }
    public static function decode_json_array(string $json): array { $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : []; }
    public static function redirect_back_with_error(string $code): string { $url = wp_get_referer() ?: home_url('/'); return add_query_arg('routespro_form_err', rawurlencode($code), $url); }
    public static function redirect_back_with_success(): string { $url = remove_query_arg('routespro_form_err', wp_get_referer() ?: home_url('/')); return add_query_arg('routespro_form_ok', '1', $url); }
}
