<?php
namespace RoutesPro\Admin;

class Appearance {
    const OPT_KEY = 'routespro_appearance';

    private static function defaults(): array {
        return [
            'primary_color'        => '#2b6cb0',
            'primary_transparent'  => 0,
            'accent_color'         => '#38b2ac',
            'accent_transparent'   => 0,
            'bg_color'             => '#f7fafc',
            'bg_transparent'       => 0,
            'font_family'          => 'system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif',
            'font_headings'        => '',         // opcional (ex.: "Inter, system-ui, sans-serif")
            'font_size_px'         => 16,         // 10–24
            // Extras de layout/estética
            'radius_px'            => 10,         // 0–24
            'input_radius_px'      => 6,          // 0–16
            'button_radius_px'     => 6,          // 0–20
            'card_shadow'          => 'sm',       // none|sm|md|lg
            'spacing_px'           => 8,          // 4–24
        ];
    }

    public static function get($key = null, $default = null) {
        $saved = get_option(self::OPT_KEY, []);
        $opts  = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
        if ($key === null) return $opts;
        return array_key_exists($key, $opts) ? $opts[$key] : $default;
    }

    private static function boolify($v): int { return isset($v) ? 1 : 0; }

    private static function hex($v, $fallback){
        $v = sanitize_hex_color($v);
        return $v ? $v : $fallback;
    }

    private static function clampInt($v, $min, $max, $fallback){
        $n = is_numeric($v) ? (int)$v : (int)$fallback;
        return max($min, min($max, $n));
    }

    private static function shadowCSS($key): string {
        switch ($key) {
            case 'lg': return '0 10px 20px rgba(0,0,0,.12), 0 6px 6px rgba(0,0,0,.08)';
            case 'md': return '0 6px 12px rgba(0,0,0,.10), 0 3px 6px rgba(0,0,0,.08)';
            case 'sm': return '0 2px 6px rgba(0,0,0,.08)';
            default:   return 'none';
        }
    }

    public static function render(){
        if (!current_user_can('routespro_manage')) return;

        if (!empty($_POST['routespro_appearance_nonce']) && wp_verify_nonce($_POST['routespro_appearance_nonce'],'routespro_appearance')) {
            $d = self::defaults();

            $opts = [
                'primary_color'        => self::hex($_POST['primary_color'] ?? $d['primary_color'], $d['primary_color']),
                'primary_transparent'  => self::boolify($_POST['primary_transparent'] ?? null),
                'accent_color'         => self::hex($_POST['accent_color'] ?? $d['accent_color'], $d['accent_color']),
                'accent_transparent'   => self::boolify($_POST['accent_transparent'] ?? null),
                'bg_color'             => self::hex($_POST['bg_color'] ?? $d['bg_color'], $d['bg_color']),
                'bg_transparent'       => self::boolify($_POST['bg_transparent'] ?? null),
                'font_family'          => sanitize_text_field($_POST['font_family'] ?? $d['font_family']),
                'font_headings'        => sanitize_text_field($_POST['font_headings'] ?? $d['font_headings']),
                'font_size_px'         => self::clampInt($_POST['font_size_px'] ?? $d['font_size_px'], 10, 24, $d['font_size_px']),
                // extras
                'radius_px'            => self::clampInt($_POST['radius_px'] ?? $d['radius_px'], 0, 24, $d['radius_px']),
                'input_radius_px'      => self::clampInt($_POST['input_radius_px'] ?? $d['input_radius_px'], 0, 16, $d['input_radius_px']),
                'button_radius_px'     => self::clampInt($_POST['button_radius_px'] ?? $d['button_radius_px'], 0, 20, $d['button_radius_px']),
                'card_shadow'          => in_array(($_POST['card_shadow'] ?? $d['card_shadow']), ['none','sm','md','lg'], true) ? $_POST['card_shadow'] : $d['card_shadow'],
                'spacing_px'           => self::clampInt($_POST['spacing_px'] ?? $d['spacing_px'], 4, 24, $d['spacing_px']),
            ];

            // preserva chaves antigas/não apresentadas em formulário
            $merged = wp_parse_args($opts, self::get());
            update_option(self::OPT_KEY, $merged);

            echo '<div class="updated notice"><p>Personalização guardada.</p></div>';
        }

        $o = self::get();

        // Variáveis para preview
        $c1  = $o['primary_transparent'] ? 'transparent' : $o['primary_color'];
        $c2  = $o['accent_transparent']  ? 'transparent' : $o['accent_color'];
        $cbg = $o['bg_transparent']      ? 'transparent' : $o['bg_color'];
        $ff  = $o['font_family'];
        $ffh = $o['font_headings'] ?: $ff;
        $fz  = (int)$o['font_size_px'];
        $rad = (int)$o['radius_px'];
        $ir  = (int)$o['input_radius_px'];
        $br  = (int)$o['button_radius_px'];
        $gap = (int)$o['spacing_px'];
        $shadow = esc_attr(self::shadowCSS($o['card_shadow']));
        ?>
        <div class="wrap">
          <h1>Personalização de Formulários</h1>
          <form method="post">
            <?php wp_nonce_field('routespro_appearance','routespro_appearance_nonce'); ?>
            <table class="form-table">
              <tr>
                <th>Cor Primária</th>
                <td>
                  <input type="color" name="primary_color" value="<?php echo esc_attr($o['primary_color']); ?>">
                  <label style="margin-left:.5rem"><input type="checkbox" name="primary_transparent" <?php checked($o['primary_transparent'],1); ?>> Transparente</label>
                </td>
              </tr>
              <tr>
                <th>Cor Accent</th>
                <td>
                  <input type="color" name="accent_color" value="<?php echo esc_attr($o['accent_color']); ?>">
                  <label style="margin-left:.5rem"><input type="checkbox" name="accent_transparent" <?php checked($o['accent_transparent'],1); ?>> Transparente</label>
                </td>
              </tr>
              <tr>
                <th>Cor de Fundo</th>
                <td>
                  <input type="color" name="bg_color" value="<?php echo esc_attr($o['bg_color']); ?>">
                  <label style="margin-left:.5rem"><input type="checkbox" name="bg_transparent" <?php checked($o['bg_transparent'],1); ?>> Transparente</label>
                </td>
              </tr>
              <tr>
                <th>Fonte (texto)</th>
                <td><input type="text" name="font_family" class="regular-text" value="<?php echo esc_attr($o['font_family']); ?>" placeholder="system-ui, -apple-system, ..."></td>
              </tr>
              <tr>
                <th>Fonte (títulos) <span style="color:#777">(opcional)</span></th>
                <td><input type="text" name="font_headings" class="regular-text" value="<?php echo esc_attr($o['font_headings']); ?>" placeholder="ex.: Inter, system-ui, sans-serif"></td>
              </tr>
              <tr>
                <th>Tamanho base (px)</th>
                <td><input type="number" name="font_size_px" min="10" max="24" value="<?php echo esc_attr($o['font_size_px']); ?>"></td>
              </tr>

              <tr><th colspan="2"><hr><strong>Extras de layout</strong></th></tr>
              <tr>
                <th>Raio dos cantos (cards)</th>
                <td><input type="number" name="radius_px" min="0" max="24" value="<?php echo esc_attr($o['radius_px']); ?>"></td>
              </tr>
              <tr>
                <th>Raio inputs</th>
                <td><input type="number" name="input_radius_px" min="0" max="16" value="<?php echo esc_attr($o['input_radius_px']); ?>"></td>
              </tr>
              <tr>
                <th>Raio botões</th>
                <td><input type="number" name="button_radius_px" min="0" max="20" value="<?php echo esc_attr($o['button_radius_px']); ?>"></td>
              </tr>
              <tr>
                <th>Sombra dos cards</th>
                <td>
                  <select name="card_shadow">
                    <?php foreach(['none'=>'Sem sombra','sm'=>'Pequena','md'=>'Média','lg'=>'Grande'] as $k=>$lbl): ?>
                      <option value="<?php echo esc_attr($k); ?>" <?php selected($o['card_shadow'],$k); ?>><?php echo esc_html($lbl); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
              <tr>
                <th>Espaçamento base (px)</th>
                <td><input type="number" name="spacing_px" min="4" max="24" value="<?php echo esc_attr($o['spacing_px']); ?>"></td>
              </tr>
            </table>

            <p><button class="button button-primary">Guardar</button></p>
          </form>

          <h2 style="margin-top:1.5em">Pré-visualização</h2>
          <div style="
            --rp-primary: <?php echo esc_attr($c1); ?>;
            --rp-accent:  <?php echo esc_attr($c2); ?>;
            --rp-bg:      <?php echo esc_attr($cbg); ?>;
            --rp-ff:      <?php echo esc_attr($ff); ?>;
            --rp-ffh:     <?php echo esc_attr($ffh); ?>;
            --rp-fz:      <?php echo esc_attr($fz); ?>px;
            --rp-radius:  <?php echo esc_attr($rad); ?>px;
            --rp-ir:      <?php echo esc_attr($ir); ?>px;
            --rp-br:      <?php echo esc_attr($br); ?>px;
            --rp-gap:     <?php echo esc_attr($gap); ?>px;
            padding: var(--rp-gap);
            font-family: var(--rp-ff);
            font-size: var(--rp-fz);
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: var(--rp-radius);
            box-shadow: <?php echo $shadow; ?>;
            max-width: 820px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--rp-gap);flex-wrap:wrap;">
              <div>
                <div style="font-family:var(--rp-ffh);font-size:20px;margin:0 0 2px">A minha rota</div>
                <div style="opacity:.8">Utilizador • 2025-01-01</div>
              </div>
              <div style="display:flex;gap:var(--rp-gap);flex-wrap:wrap;">
                <button style="background:var(--rp-accent);color:#fff;border:0;border-radius:var(--rp-br);padding:6px 10px;cursor:pointer">Exportar CSV</button>
                <button style="background:var(--rp-primary);color:#fff;border:0;border-radius:var(--rp-br);padding:6px 10px;cursor:pointer">Adicionar</button>
              </div>
            </div>

            <div style="margin-top:calc(var(--rp-gap) * 1.25);display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--rp-gap);">
              <div>
                <label style="font-size:12px;color:#555">Notas</label>
                <textarea style="width:100%;min-height:70px;border:1px solid #ddd;border-radius:var(--rp-ir);padding:6px"></textarea>
              </div>
              <div>
                <label style="font-size:12px;color:#555">Motivo de falha</label>
                <select style="width:100%;border:1px solid #ddd;border-radius:var(--rp-ir);padding:6px">
                  <option>(nenhum)</option><option>ausente</option><option>morada errada</option>
                </select>
                <div style="margin-top:6px">
                  <label style="font-size:12px;color:#555">URL foto/prova</label>
                  <input type="url" placeholder="https://..." style="width:100%;border:1px solid #ddd;border-radius:var(--rp-ir);padding:6px">
                </div>
              </div>
              <div>
                <label style="font-size:12px;color:#555">Chegada</label>
                <div style="display:flex;gap:6px">
                  <input type="datetime-local" style="flex:1;border:1px solid #ddd;border-radius:var(--rp-ir);padding:6px">
                  <button style="background:#fff;border:1px solid #ddd;border-radius:var(--rp-br);padding:6px 10px;cursor:pointer">Agora</button>
                </div>
                <div style="margin-top:6px">
                  <label style="font-size:12px;color:#555">Partida</label>
                  <div style="display:flex;gap:6px">
                    <input type="datetime-local" style="flex:1;border:1px solid #ddd;border-radius:var(--rp-ir);padding:6px">
                    <button style="background:#fff;border:1px solid #ddd;border-radius:var(--rp-br);padding:6px 10px;cursor:pointer">Agora</button>
                  </div>
                </div>
              </div>
              <div>
                <label style="font-size:12px;color:#555">Quantidade</label>
                <input type="number" style="width:100%;border:1px solid #ddd;border-radius:var(--rp-ir);padding:6px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">
                  <div>
                    <label style="font-size:12px;color:#555">Peso</label>
                    <input type="number" style="width:100%;border:1px solid #ddd;border-radius:var(--rp-ir);padding:6px">
                  </div>
                  <div>
                    <label style="font-size:12px;color:#555">Volume</label>
                    <input type="number" style="width:100%;border:1px solid #ddd;border-radius:var(--rp-ir);padding:6px">
                  </div>
                </div>
              </div>
            </div>

            <div style="margin-top:calc(var(--rp-gap) * 1.25);display:flex;gap:var(--rp-gap);justify-content:flex-end;">
              <button style="background:#fff;border:1px solid #ddd;border-radius:var(--rp-br);padding:6px 10px;cursor:pointer">Limpar</button>
              <button style="background:var(--rp-accent);color:#fff;border:0;border-radius:var(--rp-br);padding:6px 10px;cursor:pointer">Guardar reporte</button>
            </div>
          </div>
        </div>
        <?php
    }
}
