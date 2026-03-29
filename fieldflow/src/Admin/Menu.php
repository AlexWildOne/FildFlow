<?php
namespace RoutesPro\Admin;

class Menu {
    public static function register() {
        add_menu_page(
            'FieldFlow',
            'FieldFlow',
            'routespro_manage',
            'routespro',
            [self::class,'render'],
            '',
            26
        );

        add_submenu_page('routespro', 'Clientes', 'Clientes', 'routespro_manage', 'routespro-clients', [Clients::class,'render']);
        add_submenu_page('routespro', 'Projetos', 'Projetos', 'routespro_manage', 'routespro-projects', [Projects::class,'render']);
        add_submenu_page('routespro', 'Rotas', 'Rotas', 'routespro_manage', 'routespro-routes', [Routes::class,'render']);
        add_submenu_page('routespro', 'Centro de Atribuições', 'Centro de Atribuições', 'routespro_manage', 'routespro-assignments-hub', [Assignments::class,'render_hub']);
        add_submenu_page('routespro', 'Base Comercial', 'Base Comercial', 'routespro_manage', 'routespro-commercial', [Commercial::class,'render']);
        add_submenu_page('routespro', 'Importar PDVs', 'Importar PDVs', 'routespro_manage', 'routespro-commercial-import', [Commercial::class,'render_import']);
        add_submenu_page('routespro', 'Categorias', 'Categorias', 'routespro_manage', 'routespro-categories', [Categories::class,'render']);
        add_submenu_page('routespro', 'Campanhas PDVs', 'Campanhas PDVs', 'routespro_manage', 'routespro-campaign-locations', [CampaignLocations::class,'render']);
        add_submenu_page('routespro', 'Formulários', 'Formulários', 'routespro_manage', 'routespro-forms', [Forms::class,'render']);
        add_submenu_page('routespro', 'Novo formulário', 'Novo formulário', 'routespro_manage', 'routespro-form-edit', [Forms::class,'render_edit']);
        add_submenu_page('routespro', 'Submissões', 'Submissões', 'routespro_manage', 'routespro-form-submissions', [FormSubmissions::class,'render']);
        add_submenu_page(null, 'Formulários por contexto', 'Formulários por contexto', 'routespro_manage', 'routespro-form-bindings', [FormBindings::class,'render']);
        add_submenu_page(null, 'Operação & Atribuições', 'Operação & Atribuições', 'routespro_manage', 'routespro-assign', [Assignments::class,'render']);
        add_submenu_page(null, 'Editar submissão', 'Editar submissão', 'routespro_manage', 'routespro-form-submission-edit', [FormSubmissions::class,'render_edit']);
        add_submenu_page('routespro', 'Integrações', 'Integrações', 'routespro_manage', 'routespro-integrations', [Integrations::class,'render']);
        add_submenu_page('routespro', 'Importar Locais', 'Importar Locais', 'routespro_manage', 'routespro-import', [self::class,'render_import']);
        add_submenu_page('routespro', 'E-mails', 'E-mails', 'routespro_manage', 'routespro-emails', [Emails::class,'render']);
        add_submenu_page('routespro', 'E-mails enviados', 'E-mails enviados', 'routespro_manage', 'routespro-email-log', [Emails::class,'render_log']);
        add_submenu_page('routespro', 'Personalização', 'Personalização', 'routespro_manage', 'routespro-appearance', [Appearance::class,'render']);
        add_submenu_page('routespro', 'Settings', 'Settings & Categorias', 'routespro_manage', 'routespro-settings', [Settings::class,'render']);
    }

    public static function render() {
        echo '<div class="wrap">';
        Branding::render_header('FieldFlow');
        echo '<div class="routespro-card" style="margin-top:18px">';
        echo '<h2 style="margin-top:0">Shortcodes disponíveis</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Shortcode</th><th>Uso</th></tr></thead><tbody>';
        echo '<tr><td><code>[fieldflow_my_daily_route]</code></td><td>Mostra as rotas do utilizador no dia selecionado.</td></tr>';
        echo '<tr><td><code>[fieldflow_dashboard]</code></td><td>Dashboard operacional com métricas e visão geral.</td></tr>';
        echo '<tr><td><code>[fieldflow_route_change_form]</code></td><td>Formulário de pedidos de alteração de rota no front.</td></tr>';
        echo '<tr><td><code>[fieldflow_front_hub]</code></td><td>Hub front legado, agora alinhado com a app principal.</td></tr>';
        echo '<tr><td><code>[fieldflow_front_routes]</code></td><td>Fluxo front de rotas com descoberta de PDVs, fila de paragens e cálculo de distância/tempo.</td></tr>';
        echo '<tr><td><code>[fieldflow_front_commercial]</code></td><td>Base comercial no front com filtros, mapa, Google Places e gravação direta de PDVs.</td></tr>';
        echo '<tr><td><code>[fieldflow_app]</code></td><td>App principal mobile-first com Home, Minha Rota, Descoberta, Reporte e Base Comercial.</td></tr>';
        echo '<tr><td><code>[fieldflow_route_today]</code></td><td>Vista focada na rota do dia para o utilizador autenticado.</td></tr>';
        echo '<tr><td><code>[fieldflow_discovery]</code></td><td>Descoberta de PDVs e construção de rota no front.</td></tr>';
        echo '<tr><td><code>[fieldflow_report_visit]</code></td><td>Widget rápido de reporte de visita para uma paragem específica.</td></tr>';
        echo '<tr><td><code>[fieldflow_route_form]</code></td><td>Resolve automaticamente o formulário activo por cliente, projeto, rota, paragem ou local.</td></tr>';
        echo '<tr><td><code>[fieldflow_checkin]</code></td><td>Widget ultra-rápido para check-in e início de visita.</td></tr>';
        echo '<tr><td><code>[fieldflow_client_portal]</code></td><td>Portal front focado no cliente para acompanhar campanhas, rotas, execução e analytics por campanha.</td></tr>';
        echo '<tr><td><code>[fieldflow_client_team_mail]</code></td><td>Canal premium para o cliente contactar a equipa certa por campanha, com owners filtrados, tipologia da mensagem e histórico recente.</td></tr>';
        echo '</tbody></table>';
        echo '<p style="margin-top:12px;color:#64748b">Shortcodes front atualizados para suportar operação, descoberta comercial e experiência dinâmica fora do backoffice.</p>';
        echo '</div></div>';
    }

    public static function render_dashboard() {
        if (!current_user_can('routespro_manage')) return;
        echo '<div class="wrap">';
        Branding::render_header('Dashboard');
        echo do_shortcode('[fieldflow_dashboard]');
        echo '</div>';
    }

    public static function render_import() {
        if (!current_user_can('routespro_manage')) return;
        echo '<div class="wrap">';
        Branding::render_header('Importar Locais (legacy)');
        if (!empty($_POST['routespro_import_nonce']) && wp_verify_nonce($_POST['routespro_import_nonce'], 'routespro_import')) {
            \RoutesPro\Import\CSVImporter::handle_upload();
        }
        ?>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('routespro_import', 'routespro_import_nonce'); ?>
            <p><input type="file" name="routespro_csv" accept=".csv" required /></p>
            <p>
              Cliente ID: <input type="number" name="client_id" required />
              Projeto ID: <input type="number" name="project_id" />
            </p>
            <p><button class="button button-primary">Importar</button></p>
        </form>
        <?php
        echo '</div>';
    }
}
