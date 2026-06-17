<?php

/**
 * OptimizerController — renders the control page and handles its API actions.
 *
 * Page:  index()                  → GET ondemand_center
 * API:   apiServerCheck()         → detect servers + apply best settings
 *        apiAutotierSave()        → save auto-tier config
 *        apiAutotierPreview()     → dry-run the tiering plan
 *        apiAutotierApply()       → apply the tiering plan
 *
 * @package XC_VM_Module_Optimizer
 * @license AGPL-3.0
 */

require_once __DIR__ . '/OptimizerService.php';

class OptimizerController {

    protected $viewsPath;
    protected $layoutsPath;

    public function __construct() {
        $this->viewsPath = __DIR__ . '/views';
        $this->layoutsPath = MAIN_HOME . 'public/Views/layouts/';
        require_once $this->layoutsPath . 'admin.php';
        require_once $this->layoutsPath . 'footer.php';
    }

    public function index() {
        global $rMobile, $rSettings, $rServers, $language;
        $_TITLE = 'On Demand';

        $rAutoTier = OptimizerService::getAutoTierConfig();
        $rServersDetected = OptimizerService::detectServers();
        $rAvgBitrate = intval(OptimizerService::getConfig('avg_bitrate_mbps', 5));
        $rRebalance = OptimizerService::getRebalanceConfig();
        $rPlacement = OptimizerService::getPlacementConfig();

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/index.php';
        renderUnifiedLayoutFooter('admin');
    }

    // ── Server Check ─────────────────────────────────────────────────────

    public function apiServerCheck() {
        $req = RequestManager::getAll();
        if (isset($req['avg_bitrate'])) {
            OptimizerService::setConfig('avg_bitrate_mbps', max(1, intval($req['avg_bitrate'])));
        }
        $report = OptimizerService::applyServerOptimization();
        $this->json(['result' => true, 'note' => 'Best settings applied to your streaming servers (load balancers).'] + $report);
    }

    // ── Auto-Tier ────────────────────────────────────────────────────────

    public function apiAutotierSave() {
        OptimizerService::saveAutoTierConfig(RequestManager::getAll());
        $this->json(['result' => true, 'note' => 'Auto-tier settings saved.']);
    }

    public function apiAutotierPreview() {
        $this->json(['result' => true] + OptimizerService::computeTierPlan());
    }

    public function apiAutotierApply() {
        $r = OptimizerService::applyTier();
        $this->json([
            'result' => true,
            'note'   => 'Promoted ' . $r['promoted'] . ' to always-on, demoted ' . $r['demoted'] . ' to on-demand. Changes apply as streams cycle.',
        ] + $r);
    }

    // ── Rebalance ────────────────────────────────────────────────────────

    public function apiRebalanceSave() {
        OptimizerService::saveRebalanceConfig(RequestManager::getAll());
        $this->json(['result' => true, 'note' => 'Rebalance settings saved.']);
    }

    public function apiRebalancePreview() {
        $this->json(['result' => true] + OptimizerService::computeRebalancePlan());
    }

    public function apiRebalanceApply() {
        $r = OptimizerService::applyRebalance();
        $this->json(['result' => true, 'note' => 'Moved ' . $r['moved'] . ' stream(s). The daemon will start them on their new server.'] + $r);
    }

    // ── Smart Placement ──────────────────────────────────────────────────

    public function apiPlacementSave() {
        OptimizerService::savePlacementConfig(RequestManager::getAll());
        $this->json(['result' => true, 'note' => 'Placement settings saved.']);
    }

    public function apiPlacementPreview() {
        $this->json(['result' => true] + OptimizerService::computePlacementPlan());
    }

    public function apiPlacementApply() {
        $r = OptimizerService::applyPlacement();
        $this->json(['result' => true, 'note' => 'Moved ' . $r['moved'] . ' stream(s) toward optimal placement. The daemon will start them on their new server.'] + $r);
    }

    private function json(array $data): void {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
}
