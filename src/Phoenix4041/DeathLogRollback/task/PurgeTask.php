<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\task;

use pocketmine\scheduler\Task;
use Phoenix4041\DeathLogRollback\Loader;

class PurgeTask extends Task {

    private Loader $plugin;
    private int $maxAge;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
        
        $interval = $plugin->getConfigValue("purge_interval", "7d");
        $this->maxAge = $this->parseInterval($interval);
    }

    public function onRun(): void {
        $purgedCount = $this->plugin->getDataManager()->purgeOldRecords($this->maxAge);
        
        if ($purgedCount > 0) {
            $this->plugin->getLogger()->info(
                $this->plugin->getMessage("purge_complete") . " ({$purgedCount} records)"
            );
        }
    }

    private function parseInterval(string $interval): int {
        $unit = substr($interval, -1);
        $value = (int)substr($interval, 0, -1);

        return match($unit) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            'w' => $value * 604800,
            default => 604800
        };
    }
}