<?php

/**
 * On Demand Module
 *
 * Adds a single "On Demand" page to the admin panel with two smart actions:
 *   - Server Check  — detect installed servers and apply best-all-round settings.
 *   - Auto-Tier     — read usage logs and keep popular streams always-on while
 *                     demoting rarely/never-used ones to on-demand (optionally
 *                     on a daily cron).
 *
 * Loader note: directory `on-demand` → class `OptimizerModule`.
 *
 * @package XC_VM_Module_Optimizer
 * @license AGPL-3.0
 */

class OptimizerModule implements ModuleInterface {

    public function getName(): string {
        return 'on-demand';
    }

    public function getVersion(): string {
        return '0.2.0';
    }

    public function boot(ServiceContainer $container): void {
        require_once __DIR__ . '/OptimizerService.php';
        $container->set('ondemand.service', 'OptimizerService');
        $container->set('ondemand.controller', function ($c) {
            return new OptimizerController();
        });
    }

    public function registerRoutes(Router $router): void {
        require_once __DIR__ . '/OptimizerController.php';

        // NOTE: 'ondemand_center', NOT 'ondemand' — core owns the 'ondemand' URL.
        $router->group('ondemand_center', function (Router $r) {
            $r->get('', [OptimizerController::class, 'index']);
        });

        $router->api('ondemand_servercheck',      [OptimizerController::class, 'apiServerCheck']);
        $router->api('ondemand_autotier_save',    [OptimizerController::class, 'apiAutotierSave']);
        $router->api('ondemand_autotier_preview', [OptimizerController::class, 'apiAutotierPreview']);
        $router->api('ondemand_autotier_apply',   [OptimizerController::class, 'apiAutotierApply']);
        $router->api('ondemand_rebalance_save',    [OptimizerController::class, 'apiRebalanceSave']);
        $router->api('ondemand_rebalance_preview', [OptimizerController::class, 'apiRebalancePreview']);
        $router->api('ondemand_rebalance_apply',   [OptimizerController::class, 'apiRebalanceApply']);
        $router->api('ondemand_placement_save',    [OptimizerController::class, 'apiPlacementSave']);
        $router->api('ondemand_placement_preview', [OptimizerController::class, 'apiPlacementPreview']);
        $router->api('ondemand_placement_apply',   [OptimizerController::class, 'apiPlacementApply']);
    }

    public function registerCommands(CommandRegistry $registry): void {
        require_once __DIR__ . '/AutoTierCronJob.php';
        require_once __DIR__ . '/RebalanceCronJob.php';
        require_once __DIR__ . '/PlacementCronJob.php';
        $registry->register(new AutoTierCronJob());
        $registry->register(new RebalanceCronJob());
        $registry->register(new PlacementCronJob());
    }

    public function getEventSubscribers(): array {
        return [];
    }

    public function install(): void {
        require_once __DIR__ . '/OptimizerService.php';
        OptimizerService::ensureSchema();
    }

    public function uninstall(): void {
        global $db;
        require_once __DIR__ . '/OptimizerService.php';
        OptimizerService::setCron(OptimizerService::AUTOTIER_FILENAME, '17 4 * * *', false);
        OptimizerService::setCron(OptimizerService::REBALANCE_FILENAME, '*/30 * * * *', false);
        OptimizerService::setCron(OptimizerService::PLACEMENT_FILENAME, '0 * * * *', false);
        if (isset($db)) {
            $db->query("DROP TABLE IF EXISTS `ondemand_priority`;");
            $db->query("DROP TABLE IF EXISTS `ondemand_config`;");
        }
    }

    public function registerNavbar(): void {
        // Own top-level menu entry (siblings: dashboard, servers, management, …).
        NavbarRegistry::add(
            (new NavbarItem('ondemand_center'))
                ->url('ondemand_center')
                ->label('', 'Server Optimizer')
                ->icon('fas fa-bolt')
                ->order(650)
        );
    }
}
