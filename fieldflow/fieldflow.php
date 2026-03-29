<?php
/**
 * Plugin Name: FieldFlow
 * Description: Plataforma profissional de rotas, equipas de terreno e reporting para merchandisers, promotores, equipas comerciais e clientes.
 * Version: 0.9.50
 * Author: The Wild Theory
 * Text Domain: routes-pro
 */
if (!defined('ABSPATH')) exit;

define('FIELDFLOW_VERSION', '0.9.43');
define('FIELDFLOW_PATH', plugin_dir_path(__FILE__));
define('FIELDFLOW_URL', plugin_dir_url(__FILE__));

// Compatibilidade retroativa para instalações existentes e código legado.
if (!defined('ROUTESPRO_VERSION')) define('ROUTESPRO_VERSION', FIELDFLOW_VERSION);
if (!defined('ROUTESPRO_PATH')) define('ROUTESPRO_PATH', FIELDFLOW_PATH);
if (!defined('ROUTESPRO_URL')) define('ROUTESPRO_URL', FIELDFLOW_URL);

require_once ROUTESPRO_PATH . 'src/Activator.php';
require_once ROUTESPRO_PATH . 'src/Support/GeoPT.php';
require_once ROUTESPRO_PATH . 'src/Support/AssignmentMatrix.php';
require_once ROUTESPRO_PATH . 'src/Support/AssignmentResolver.php';
require_once ROUTESPRO_PATH . 'src/Support/Permissions.php';

require_once ROUTESPRO_PATH . 'src/Admin/Branding.php';
require_once ROUTESPRO_PATH . 'src/Admin/Menu.php';
require_once ROUTESPRO_PATH . 'src/Admin/Clients.php';
require_once ROUTESPRO_PATH . 'src/Admin/Projects.php';
require_once ROUTESPRO_PATH . 'src/Admin/Integrations.php';
require_once ROUTESPRO_PATH . 'src/Admin/Routes.php';
require_once ROUTESPRO_PATH . 'src/Admin/Assignments.php';
require_once ROUTESPRO_PATH . 'src/Admin/Settings.php';
require_once ROUTESPRO_PATH . 'src/Admin/ajax.php';
require_once ROUTESPRO_PATH . 'src/Admin/Emails.php';
require_once ROUTESPRO_PATH . 'src/Admin/Appearance.php';
require_once ROUTESPRO_PATH . 'src/Admin/Commercial.php';
require_once ROUTESPRO_PATH . 'src/Admin/Categories.php';
require_once ROUTESPRO_PATH . 'src/Admin/CampaignLocations.php';
require_once ROUTESPRO_PATH . 'src/Admin/Forms.php';
require_once ROUTESPRO_PATH . 'src/Admin/FormSubmissions.php';
require_once ROUTESPRO_PATH . 'src/Admin/FormBindings.php';

require_once ROUTESPRO_PATH . 'src/Forms/FormRenderer.php';
require_once ROUTESPRO_PATH . 'src/Forms/BindingResolver.php';
require_once ROUTESPRO_PATH . 'src/Forms/Forms.php';

require_once ROUTESPRO_PATH . 'src/Import/CSVImporter.php';

require_once ROUTESPRO_PATH . 'src/Rest/RoutesController.php';
require_once ROUTESPRO_PATH . 'src/Rest/LocationsController.php';
require_once ROUTESPRO_PATH . 'src/Rest/EventsController.php';
require_once ROUTESPRO_PATH . 'src/Rest/OptimizeController.php';
require_once ROUTESPRO_PATH . 'src/Rest/StatsController.php';
require_once ROUTESPRO_PATH . 'src/Rest/IntegrationsController.php';
require_once ROUTESPRO_PATH . 'src/Rest/CategoriesController.php';
require_once ROUTESPRO_PATH . 'src/Rest/CommercialController.php';
require_once ROUTESPRO_PATH . 'src/Rest/PlacesController.php';

require_once ROUTESPRO_PATH . 'src/Services/Maps.php';
require_once ROUTESPRO_PATH . 'src/Services/AI.php';
require_once ROUTESPRO_PATH . 'src/Services/Notify.php';
require_once ROUTESPRO_PATH . 'src/Services/RouteSnapshotService.php';
require_once ROUTESPRO_PATH . 'src/Services/LocationDeduplicator.php';
require_once ROUTESPRO_PATH . 'src/Services/IntegrationPlatform.php';

require_once ROUTESPRO_PATH . 'src/Shortcodes.php';
require_once ROUTESPRO_PATH . 'src/Elementor/Register.php';

register_activation_hook(__FILE__, ['RoutesPro\Activator','activate']);


add_action('plugins_loaded', function () {
    $installed = (string) get_option('routespro_version', '');
    if ($installed !== ROUTESPRO_VERSION) {
        \RoutesPro\Activator::activate();
    }
});


add_action('admin_post_routespro_download_commercial_template', function(){
    if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
    $headers = ['name','address','district','county','city','parish','postal_code','country','category','subcategory','contact_person','phone','email','website','lat','lng','external_ref','place_id','source'];
    $sample = ['Exemplo PDV','Rua Exemplo 123','Lisboa','Lisboa','Lisboa','','1000-100','Portugal','Horeca','Restaurante','Joao Silva','910000000','geral@example.com','https://example.com','38.7223','-9.1393','REF-001','','csv'];
    $csv = implode(',', $headers) . "
" . implode(',', array_map(function($v){ return '"' . str_replace('"', '""', $v) . '"'; }, $sample)) . "
";
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="routespro-commercial-template.csv"');
    echo $csv;
    exit;
});


add_action('init', function () {
    load_plugin_textdomain('routes-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('rest_api_init', function () {
    (new RoutesPro\Rest\LocationsController())->register_routes();
    (new RoutesPro\Rest\RoutesController())->register_routes();
    (new RoutesPro\Rest\EventsController())->register_routes();
    (new RoutesPro\Rest\OptimizeController())->register_routes();
    (new RoutesPro\Rest\StatsController())->register_routes();
    (new RoutesPro\Rest\IntegrationsController())->register_routes();
    (new RoutesPro\Rest\CategoriesController())->register_routes();
    (new RoutesPro\Rest\CommercialController())->register_routes();
    (new RoutesPro\Rest\PlacesController())->register_routes();
});

add_action('admin_menu', ['RoutesPro\Admin\Menu', 'register']);
add_action('init', ['RoutesPro\Shortcodes', 'register']);
add_action('init', ['RoutesPro\Forms\Forms', 'boot']);
add_action('admin_init', ['RoutesPro\Admin\Forms', 'register_hooks']);
add_action('admin_init', ['RoutesPro\Admin\FormSubmissions', 'register_hooks']);
add_action('admin_init', ['RoutesPro\Admin\FormBindings', 'register_hooks']);
add_action('admin_init', ['RoutesPro\Admin\Branding', 'enqueue_menu_branding']);

add_action('elementor/widgets/register', function($widgets_manager){
    if (class_exists('\\RoutesPro\\Elementor\\Register')) {
        \RoutesPro\Elementor\Register::register_widgets($widgets_manager);
    }
});


add_action('admin_post_routespro_export_commercial_existing', function(){
    if (!current_user_can('routespro_manage')) wp_die('Forbidden');
    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $client_id = absint($_GET['client_id'] ?? 0);
    $project_id = absint($_GET['project_id'] ?? 0);
    $sql = "SELECT DISTINCT l.*, c.name AS category_name, sc.name AS subcategory_name FROM {$px}locations l LEFT JOIN {$px}categories c ON c.id=l.category_id LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id LEFT JOIN {$px}campaign_locations cl ON cl.location_id=l.id LEFT JOIN {$px}projects p ON p.id=cl.project_id WHERE 1=1";
    $args = [];
    if ($project_id) { $sql .= " AND cl.project_id=%d"; $args[] = $project_id; } elseif ($client_id) { $sql .= " AND p.client_id=%d"; $args[] = $client_id; }
    $sql .= " ORDER BY l.id DESC";
    $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="routespro-commercial-existing.csv"');
    $out = fopen('php://output', 'w');
    $headers = ['id','name','address','district','county','city','parish','postal_code','country','category','subcategory','contact_person','phone','email','website','lat','lng','external_ref','place_id','source','is_active'];
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '', $r['name'] ?? '', $r['address'] ?? '', $r['district'] ?? '', $r['county'] ?? '', $r['city'] ?? '', $r['parish'] ?? '', $r['postal_code'] ?? '', $r['country'] ?? '',
            $r['category_name'] ?? '', $r['subcategory_name'] ?? '', $r['contact_person'] ?? '', $r['phone'] ?? '', $r['email'] ?? '', $r['website'] ?? '', $r['lat'] ?? '', $r['lng'] ?? '',
            $r['external_ref'] ?? '', $r['place_id'] ?? '', $r['source'] ?? '', $r['is_active'] ?? 1
        ]);
    }
    fclose($out); exit;
});


// FieldFlow autocomplete endpoint
add_action('rest_api_init', function () {
    register_rest_route('fieldflow/v1', '/pdvs-search', [
        'methods' => 'GET',
        'callback' => function($request){
            global $wpdb;
            $q = sanitize_text_field($request->get_param('q'));
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT id, nome, cidade FROM {$wpdb->prefix}ff_pdvs WHERE nome LIKE %s LIMIT 10",
                "%$q%"
            ));
            return $results;
        },
        'permission_callback' => '__return_true'
    ]);
});


// enqueue autocomplete
add_action('wp_enqueue_scripts', function(){
    wp_enqueue_script('ff-autocomplete', plugin_dir_url(__FILE__).'assets/ff-autocomplete.js', [], '1.0', true);
});
