<?php
namespace RoutesPro\Elementor;

if (!defined('ABSPATH')) { exit; }

class Register {

    /**
     * Chama isto no boot do plugin:
     * add_action('elementor/widgets/register', [\RoutesPro\Elementor\Register::class, 'register_widgets']);
     * add_action('elementor/elements/categories_registered', [\RoutesPro\Elementor\Register::class, 'register_category']);
     */

    public static function register_category($elements_manager){
        // Garante que a categoria existe para agrupar os widgets do FieldFlow
        $elements_manager->add_category(
            'fieldflow',
            [
                'title' => __('FieldFlow', 'routespro'),
                'icon'  => 'fa fa-map',
            ]
        );
    }

    public static function register_widgets($widgets_manager) {
        // Lista de widgets a registar. Cada ficheiro deve declarar a classe abaixo.
        $widgets = [
            [
                'file'  => dirname(__FILE__) . '/Widgets/DashboardWidget.php',
                'class' => '\\FieldFlow\\Elementor\\Widgets\\DashboardWidget',
            ],
            [
                'file'  => dirname(__FILE__) . '/Widgets/MyDailyRouteWidget.php',
                'class' => '\\FieldFlow\\Elementor\\Widgets\\MyDailyRouteWidget',
            ],
            [
                'file'  => dirname(__FILE__) . '/Widgets/RouteChangeFormWidget.php',
                'class' => '\\FieldFlow\\Elementor\\Widgets\\RouteChangeFormWidget',
            ],
        ];

        foreach ($widgets as $w) {
            self::safe_register($widgets_manager, $w['file'], $w['class']);
        }
    }

    /**
     * Carrega um ficheiro de widget e regista a classe caso exista.
     */
    private static function safe_register($widgets_manager, $file, $class){
        if (is_readable($file)) {
            require_once $file;
            if (class_exists($class)) {
                // A API moderna do Elementor usa $widgets_manager->register()
                $widgets_manager->register(new $class());
            }
        } else {
            // Opcional: log para debugging sem quebrar execução
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[FieldFlow] Elementor widget file not found: ' . $file);
            }
        }
    }
}
