<?php
namespace RoutesPro\Elementor\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if (!defined('ABSPATH')) { exit; }

class DashboardWidget extends Widget_Base {

    public function get_name() {
        return 'fieldflow_dashboard';
    }

    public function get_title() {
        return __('FieldFlow Dashboard', 'routespro');
    }

    public function get_icon() {
        return 'eicon-dashboard';
    }

    public function get_categories() {
        // Usa a categoria personalizada criada em src/elementor/register.php
        return ['fieldflow'];
    }

    protected function register_controls() {
        $this->start_controls_section('content', ['label' => __('Conteúdo', 'routespro')]);

        $this->add_control('show_filters', [
            'label'   => __('Mostrar Filtros', 'routespro'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);

        $this->add_control('default_from', [
            'label'       => __('Data "De" (YYYY-MM-DD)', 'routespro'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => 'YYYY-MM-DD',
            'description' => __('Se vazio, usa últimos 7 dias.', 'routespro'),
        ]);

        $this->add_control('default_to', [
            'label'       => __('Data "Até" (YYYY-MM-DD)', 'routespro'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => 'YYYY-MM-DD',
            'description' => __('Se vazio, usa hoje.', 'routespro'),
        ]);

        // Enquanto o shortcode suportar Cliente/Projeto, damos opção de pré-seleção:
        $this->add_control('preset_client_id', [
            'label'       => __('Cliente por omissão (ID)', 'routespro'),
            'type'        => Controls_Manager::NUMBER,
            'min'         => 0,
            'step'        => 1,
            'description' => __('Deixa vazio para não forçar cliente.', 'routespro'),
        ]);

        $this->add_control('preset_project_id', [
            'label'       => __('Projeto por omissão (ID)', 'routespro'),
            'type'        => Controls_Manager::NUMBER,
            'min'         => 0,
            'step'        => 1,
            'description' => __('Deixa vazio para não forçar projeto.', 'routespro'),
        ]);

        $this->end_controls_section();
    }

    protected function render() {
        if (!current_user_can('routespro_manage')) {
            echo '<p>' . esc_html__('Sem permissões.', 'routespro') . '</p>';
            return;
        }

        $settings = $this->get_settings_for_display();
        $show_filters     = !empty($settings['show_filters']) && $settings['show_filters'] === 'yes';
        $default_from     = trim((string)($settings['default_from'] ?? ''));
        $default_to       = trim((string)($settings['default_to'] ?? ''));
        $preset_client_id = (int)($settings['preset_client_id'] ?? 0);
        $preset_project_id= (int)($settings['preset_project_id'] ?? 0);

        // Render do shortcode tal como está no plugin
        echo do_shortcode('[fieldflow_dashboard]');

        // Aplicar presets via JS não intrusivo (só se existirem os elementos do shortcode)
        ?>
        <script>
        (function(){
            var box   = document.querySelector('.routespro-dashboard');
            if(!box) return;

            // Mostrar/ocultar filtros
            <?php if (!$show_filters): ?>
            var rows = box.querySelectorAll('.row');
            rows.forEach(function(r){ r.style.display = 'none'; });
            <?php endif; ?>

            // Helpers
            function setValue(sel, val){
                var el = box.querySelector(sel);
                if (!el || val === '' || val === null || typeof val === 'undefined') return;
                el.value = val;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // Datas por omissão
            <?php if ($default_from !== ''): ?>
            setValue('#db-from', '<?php echo esc_js($default_from); ?>');
            <?php endif; ?>
            <?php if ($default_to !== ''): ?>
            setValue('#db-to', '<?php echo esc_js($default_to); ?>');
            <?php endif; ?>

            // Pré-seleção de Cliente/Projeto (respeita o que o shortcode disponibiliza hoje)
            <?php if ($preset_client_id > 0): ?>
            setValue('#db-client', String(<?php echo (int)$preset_client_id; ?>));
            <?php endif; ?>
            <?php if ($preset_project_id > 0): ?>
            // Aguarda um pequeno tempo para o carregamento dos projetos por AJAX,
            // depois tenta aplicar o preset.
            setTimeout(function(){
                setValue('#db-project', String(<?php echo (int)$preset_project_id; ?>));
            }, 600);
            <?php endif; ?>
        })();
        </script>
        <?php
    }
}
