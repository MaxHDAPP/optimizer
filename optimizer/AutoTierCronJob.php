<?php

/**
 * AutoTierCronJob — daily auto-tiering based on usage logs.
 *
 * Reads streams_stats (popularity) and flips on_demand flags:
 *   popular (top-N / threshold / keep-warm list) → on_demand=0 (always-on)
 *   the rest (only if "demote" is enabled)        → on_demand=1 (on-demand)
 *
 * Scheduled via XC_VM's crontab table (see OptimizerService::saveAutoTierConfig).
 *
 * @package XC_VM_Module_Optimizer
 * @license AGPL-3.0
 */

require_once MAIN_HOME . 'cli/CronTrait.php';
require_once __DIR__ . '/OptimizerService.php';

class AutoTierCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:ondemand-autotier';
    }

    public function getDescription(): string {
        return 'Cron: On Demand auto-tier — popular streams always-on, idle streams on-demand';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        set_time_limit(0);
        $this->setProcessTitle('XC_VM[Optimizer AutoTier]');

        $r = OptimizerService::applyTier();
        echo 'On Demand auto-tier: promoted ' . $r['promoted'] . ' to always-on, demoted ' . $r['demoted'] . " to on-demand.\n";

        return 0;
    }
}
