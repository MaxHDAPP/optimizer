<?php

/**
 * PlacementCronJob — capability-aware stream placement (hourly).
 *
 * Places popular streams on strong servers and rarely-used ones on weak servers
 * (capped per run to limit churn). Relay rows only; origin is kept. The daemon
 * stops/starts streams on their new servers.
 *
 * Scheduled via XC_VM's crontab table (see OptimizerService::savePlacementConfig).
 *
 * @package XC_VM_Module_Optimizer
 * @license AGPL-3.0
 */

require_once MAIN_HOME . 'cli/CronTrait.php';
require_once __DIR__ . '/OptimizerService.php';

class PlacementCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:ondemand-placement';
    }

    public function getDescription(): string {
        return 'Cron: smart placement — popular streams on strong servers, idle ones on weak servers';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        set_time_limit(0);
        $this->setProcessTitle('XC_VM[Optimizer Placement]');

        $r = OptimizerService::applyPlacement();
        echo 'On Demand placement: moved ' . $r['moved'] . " stream(s) toward optimal placement.\n";

        return 0;
    }
}
