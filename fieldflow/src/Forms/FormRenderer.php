<?php
namespace RoutesPro\Forms;
if (!defined('ABSPATH')) exit;
class FormRenderer {
    public static function enqueue_assets() {
        wp_register_style('routespro-form-renderer', false, [], ROUTESPRO_VERSION); wp_enqueue_style('routespro-form-renderer'); wp_add_inline_style('routespro-form-renderer', self::inline_css());
        wp_register_script('routespro-form-renderer', false, ['jquery'], ROUTESPRO_VERSION, true); wp_enqueue_script('routespro-form-renderer'); wp_add_inline_script('routespro-form-renderer', self::inline_js());
    }
    public static function theme_style_attr(array $theme): string { $parts=[]; if (!empty($theme['primary'])) $parts[]='--rp-form-primary:'.sanitize_hex_color($theme['primary']); if (!empty($theme['primary_hover'])) $parts[]='--rp-form-primary-hover:'.sanitize_hex_color($theme['primary_hover']); if (isset($theme['radius'])&&$theme['radius']!=='') $parts[]='--rp-form-radius:'.max(0,min(30,(int)$theme['radius'])).'px'; return implode(';', array_filter($parts)); }
    public static function render_questions(array $schema): string {
        $questions = isset($schema['questions']) && is_array($schema['questions']) ? $schema['questions'] : []; if (!$questions) return '<p>Formulário sem perguntas.</p>';
        $layout = isset($schema['layout']) && is_array($schema['layout']) ? $schema['layout'] : []; $mode = isset($layout['mode']) ? sanitize_key($layout['mode']) : 'single'; if (!in_array($mode,['single','steps'],true)) $mode='single'; $field_layout = isset($layout['field_layout']) && is_array($layout['field_layout']) ? $layout['field_layout'] : [];
        $title = sanitize_text_field($schema['meta']['title'] ?? ''); $subtitle = sanitize_text_field($schema['meta']['subtitle'] ?? ''); $index=[]; foreach ($questions as $q) { if (!is_array($q)) continue; $key = sanitize_key($q['key'] ?? ''); if ($key) $index[$key]=$q; }
        $out=''; if ($title || $subtitle) { $out.='<div class="routespro-form-section">'; if ($title) $out.='<h3 class="routespro-form-section-title">'.esc_html($title).'</h3>'; if ($subtitle) $out.='<div class="routespro-form-help">'.esc_html($subtitle).'</div>'; $out.='</div>'; }
        if ($mode === 'steps') {
            $steps = isset($layout['steps']) && is_array($layout['steps']) ? $layout['steps'] : []; if (!$steps) $steps=[['title'=>'','description'=>'','fields'=>array_keys($index)]]; $total=count($steps); $show_progress=!empty($layout['show_progress']) && $total>1; $out.='<div class="routespro-form-wizard" data-steps-total="'.esc_attr((string)$total).'">'; if($show_progress){$out.='<div class="routespro-form-progress"><div class="routespro-form-progress-bar"><span class="routespro-form-progress-fill" style="width:0%"></span></div><div class="routespro-form-progress-label" data-rp-progress-label>0%</div></div>';}
            foreach($steps as $i=>$st){$step_index=$i+1; $title_step=sanitize_text_field($st['title'] ?? ''); $desc_step=sanitize_text_field($st['description'] ?? ''); $fields=isset($st['fields'])&&is_array($st['fields'])?$st['fields']:[]; $out.='<div class="routespro-form-step" data-rp-step data-step-index="'.esc_attr((string)$step_index).'"'.($step_index===1?'':' hidden').'>'; if($title_step) $out.='<h3 class="routespro-form-step-title">'.esc_html($title_step).'</h3>'; if($desc_step) $out.='<div class="routespro-form-help">'.esc_html($desc_step).'</div>'; $out.='<div class="routespro-form-grid routespro-form-cols-2">'; foreach($fields as $key){$key=sanitize_key($key); if(empty($index[$key])) continue; $width=isset($field_layout[$key]['width'])?(int)$field_layout[$key]['width']:100; $out.=self::wrap_field_width(self::render_field($index[$key]), $width);} $out.='</div><div class="routespro-form-wizard-actions">'; if($step_index>1) $out.='<button type="button" class="routespro-form-btn secondary" data-rp-prev>Anterior</button>'; if($step_index<$total) $out.='<button type="button" class="routespro-form-btn" data-rp-next>Seguinte</button>'; else $out.='<button type="button" class="routespro-form-btn" data-rp-submit>Submeter</button>'; $out.='</div></div>';}
            return $out.'</div>';
        }
        $out.='<div class="routespro-form-section"><div class="routespro-form-grid routespro-form-cols-1">'; foreach($questions as $q){ if (!is_array($q)) continue; $out.=self::render_field($q);} return $out.'</div></div>';
    }
    public static function schema_needs_multipart(array $schema): bool { foreach(($schema['questions'] ?? []) as $q){ if(!is_array($q)) continue; $type=sanitize_key($q['type'] ?? ''); if(in_array($type,['image_upload','file_upload'],true)) return true; } return false; }
    private static function wrap_field_width(string $html, int $width): string { $allowed=[25,33,50,66,75,100]; $width=in_array($width,$allowed,true)?$width:100; return '<div class="routespro-form-col routespro-form-w-'.esc_attr((string)$width).'">'.$html.'</div>'; }
    private static function render_field(array $q): string {
        $key=sanitize_key($q['key'] ?? ''); if(!$key) return ''; $label=sanitize_text_field($q['label'] ?? $key); $type=sanitize_key($q['type'] ?? 'text'); $required=!empty($q['required']); $help=sanitize_text_field($q['help_text'] ?? ''); $min=$q['min'] ?? ''; $max=$q['max'] ?? ''; $unit=sanitize_text_field($q['unit'] ?? ''); $options=is_array($q['options'] ?? null)?$q['options']:[]; $req=$required?' required':''; $out='<div class="routespro-form-field routespro-form-type-'.esc_attr($type).'">';
        if($type==='checkbox'){ $out.='<label class="routespro-form-check"><input type="checkbox" name="'.esc_attr($key).'" value="1"> <span>'.esc_html($label).($required?' *':'').'</span></label>'; }
        elseif($type==='radio'){ $out.='<fieldset class="routespro-form-fieldset"><legend>'.esc_html($label).($required?' *':'').'</legend>'; foreach($options as $opt){ $opt=sanitize_text_field($opt); $out.='<label class="routespro-form-check"><input type="radio" name="'.esc_attr($key).'" value="'.esc_attr($opt).'"'.$req.'> <span>'.esc_html($opt).'</span></label>'; } $out.='</fieldset>'; }
        else { $out.='<label for="'.esc_attr($key).'">'.esc_html($label).($required?' *':'').'</label>'; switch($type){ case 'textarea': $out.='<textarea id="'.esc_attr($key).'" name="'.esc_attr($key).'" rows="4"'.$req.'></textarea>'; break; case 'number': case 'currency': case 'percent': $out.='<div class="routespro-form-input-group">'; if($type==='currency') $out.='<span class="routespro-form-addon">€</span>'; $out.='<input type="number" step="0.01" id="'.esc_attr($key).'" name="'.esc_attr($key).'"'.($min!==''?' min="'.esc_attr((string)$min).'"':'').($max!==''?' max="'.esc_attr((string)$max).'"':'').$req.'>'; if($type==='percent') $out.='<span class="routespro-form-addon">%</span>'; elseif($unit) $out.='<span class="routespro-form-addon">'.esc_html($unit).'</span>'; $out.='</div>'; break; case 'date': $out.='<input type="date" id="'.esc_attr($key).'" name="'.esc_attr($key).'"'.$req.'>'; break; case 'time': $out.='<input type="time" id="'.esc_attr($key).'" name="'.esc_attr($key).'"'.$req.'>'; break; case 'select': $out.='<select id="'.esc_attr($key).'" name="'.esc_attr($key).'"'.$req.'><option value="">Selecionar</option>'; foreach($options as $opt){$opt=sanitize_text_field($opt); $out.='<option value="'.esc_attr($opt).'">'.esc_html($opt).'</option>';} $out.='</select>'; break; case 'image_upload': $out.='<input type="file" accept="image/*" id="'.esc_attr($key).'" name="'.esc_attr($key).'"'.$req.'>'; break; case 'file_upload': $out.='<input type="file" id="'.esc_attr($key).'" name="'.esc_attr($key).'"'.$req.'>'; break; default: $out.='<input type="text" id="'.esc_attr($key).'" name="'.esc_attr($key).'"'.$req.'>'; break; } }
        if($help) $out.='<div class="routespro-form-help">'.esc_html($help).'</div>'; return $out.'</div>';
    }
    private static function inline_css(): string { return '.rp-form-ui{--rp-form-primary:#2271b1;--rp-form-primary-hover:#135e96;--rp-form-radius:14px;color:#111827;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}.routespro-form-section{background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:var(--rp-form-radius);padding:16px;margin:0 0 14px}.routespro-form-section-title,.routespro-form-step-title{font-size:18px;margin:0 0 8px}.routespro-form-help{font-size:13px;opacity:.78;margin-top:6px;line-height:1.35}.routespro-form-grid{display:grid;gap:12px}.routespro-form-cols-1{grid-template-columns:1fr}.routespro-form-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.routespro-form-col{min-width:0}.routespro-form-w-25,.routespro-form-w-33,.routespro-form-w-50{grid-column:span 1}.routespro-form-w-66,.routespro-form-w-75,.routespro-form-w-100{grid-column:span 2}.routespro-form-field label,.routespro-form-field legend{display:block;margin:0 0 6px;font-size:13px}.routespro-form-field input[type=text],.routespro-form-field input[type=number],.routespro-form-field input[type=date],.routespro-form-field input[type=time],.routespro-form-field input[type=file],.routespro-form-field select,.routespro-form-field textarea{width:100%;box-sizing:border-box;border:1px solid rgba(0,0,0,.18);border-radius:calc(var(--rp-form-radius) - 2px);padding:10px 12px;background:#fff}.routespro-form-fieldset{border:0;padding:0;margin:0;min-width:0}.routespro-form-check{display:flex;gap:10px;align-items:flex-start;margin:8px 0}.routespro-form-input-group{display:flex;align-items:center;border:1px solid rgba(0,0,0,.18);border-radius:calc(var(--rp-form-radius) - 2px);overflow:hidden;background:#fff}.routespro-form-input-group input{border:0!important;border-radius:0!important}.routespro-form-addon{padding:0 10px;opacity:.75;white-space:nowrap}.routespro-form-btn{appearance:none;border:0;border-radius:999px;padding:11px 16px;background:var(--rp-form-primary);color:#fff;font-weight:600;cursor:pointer}.routespro-form-btn:hover{background:var(--rp-form-primary-hover)}.routespro-form-btn.secondary{background:#f3f4f6;color:#111827}.routespro-form-actions,.routespro-form-wizard-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.routespro-form-progress{margin:0 0 14px}.routespro-form-progress-bar{height:10px;background:#eef2f7;border-radius:999px;overflow:hidden}.routespro-form-progress-fill{display:block;height:100%;background:var(--rp-form-primary)}.routespro-form-progress-label{font-size:12px;opacity:.75;margin-top:6px}.routespro-form-notice{padding:12px 14px;border-radius:12px;margin:0 0 12px}.routespro-form-notice.success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}.routespro-form-notice.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}@media(max-width:720px){.routespro-form-cols-2{grid-template-columns:1fr}.routespro-form-w-66,.routespro-form-w-75,.routespro-form-w-100{grid-column:span 1}}'; }
    private static function inline_js(): string {
        return <<<'JS'
jQuery(function($){
  $('.routespro-dyn-form').each(function(){
    var $form=$(this),$wizard=$form.find('.routespro-form-wizard');
    if(!$wizard.length) return;
    var $steps=$wizard.find('[data-rp-step]');
    if(!$steps.length) return;
    var total=parseInt($wizard.attr('data-steps-total')||$steps.length,10)||$steps.length;
    var index=1;
    function showStep(n){
      index=Math.max(1,Math.min(total,n));
      $steps.each(function(){
        var $s=$(this),i=parseInt($s.attr('data-step-index')||'0',10);
        $s.prop('hidden',i!==index);
      });
      var pct=total<=1?100:Math.round(((index-1)/(total-1))*100);
      $wizard.find('.routespro-form-progress-fill').css('width',pct+'%');
      $wizard.find('[data-rp-progress-label]').text(pct+'%');
    }
    function validateScope($scope){
      var ok=true;
      $scope.find('[required]').each(function(){
        if(this.type==='radio'){
          var name=$(this).attr('name');
          if(!$scope.find('input[type=radio][name="'+name+'"]:checked').length){ ok=false; return false; }
        } else if(!$(this).val()) { ok=false; return false; }
      });
      return ok;
    }
    $wizard.on('click','[data-rp-next]',function(){
      var $current=$wizard.find('[data-rp-step][data-step-index="'+index+'"]');
      if(!validateScope($current)){ alert('Há campos obrigatórios por preencher.'); return; }
      showStep(index+1);
    });
    $wizard.on('click','[data-rp-prev]',function(){ showStep(index-1); });
    $wizard.on('click','[data-rp-submit]',function(){
      if(!validateScope($form)){ alert('Há campos obrigatórios por preencher.'); return; }
      $form.trigger('submit');
    });
    $form.find('.routespro-form-actions').prop('hidden',true);
    showStep(1);
  });
});
JS;
    }
}
