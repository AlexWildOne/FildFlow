<?php
namespace RoutesPro\Admin;

if (!defined('ABSPATH')) exit;

class Branding {
    public static function render_header(string $title = 'Routes Pro'): void {
        $logo = ROUTESPRO_URL . 'assets/logo-twt.png';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;margin:16px 0 20px 0">';
        echo '<div style="display:flex;align-items:center;gap:14px">';
        echo '<img src="' . esc_url($logo) . '" alt="The Wild Theory" style="height:42px;width:auto;display:block" />';
        echo '<div>';
        echo '<h1 style="margin:0;font-size:22px;line-height:1.2">' . esc_html($title) . '</h1>';
        echo '<div style="margin-top:4px;color:#6b7280;font-size:12px">Propriedade Intelectual da The Wild Theory</div>';
        echo '</div>';
        echo '</div>';
        echo '<div style="color:#111827;font-size:12px;font-weight:600">Routes Pro Commercial</div>';
        echo '</div>';
    }

    public static function enqueue_menu_branding(): void {
        add_action('admin_head', function () {
            $logo = esc_url(ROUTESPRO_URL . 'assets/logo-twt.png');
            echo '<style>
                #toplevel_page_routespro .wp-menu-image img{padding:4px 0 0 0;opacity:1;max-width:20px;height:auto}
                .routespro-meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:12px 0 16px}
                .routespro-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px}
                .routespro-flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
                .routespro-hidden{display:none}
                .routespro-map{height:420px;border:1px solid #d1d5db;border-radius:12px;overflow:hidden;background:#f9fafb}
            </style>';
            echo '<script>document.addEventListener("DOMContentLoaded",function(){var mi=document.querySelector("#toplevel_page_routespro .wp-menu-image");if(mi&&!mi.querySelector("img")){mi.innerHTML="<img src=\'' . $logo . '\' alt=\'TWT\' />";}});</script>';
        });
    }
}
