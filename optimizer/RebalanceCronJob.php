<?php

/**
 * RebalanceCronJob — relieve overloaded load balancers automatically.
 *
 * Moves relay streams off any LB above the configured load threshold onto LBs
 * with spare capacity (keeping each stream's origin/parent intact). The
 * streaming daemon then stops the stream on the old LB and starts it on the new.
 *
 * Scheduled via XC_VM's crontab table (see OptimizerService::saveRebalanceConfig).
 *
 * @package XC_VM_Module_Optimizer
 * @license AGPL-3.0
 */

require_once MAIN_HOME . 'cli/CronTrait.php';
require_once __DIR__ . '/OptimizerService.php';

class RebalanceCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:ondemand-rebalance';
    }

    public function getDescription(): string {
        return 'Cron: relieve overloaded load balancers by moving streams to ones with spare capacity';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        set_time_limit(0);
        $this->setProcessTitle('XC_VM[Optimizer Rebalance]');

        $r = OptimizerService::applyRebalance();
        echo 'On Demand rebalance: moved ' . $r['moved'] . " stream(s) to relieve overloaded load balancers.\n";

        return 0;
    }
}
