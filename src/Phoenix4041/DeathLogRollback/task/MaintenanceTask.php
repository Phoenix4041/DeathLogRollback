<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\task;

use pocketmine\scheduler\Task;
use Phoenix4041\DeathLogRollback\Loader;

class MaintenanceTask extends Task {

    private Loader $plugin;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $clearedLocks = $this->plugin->getRollbackManager()->clearExpiredLocks();
        
        if ($clearedLocks > 0 && $this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("[MaintenanceTask] Cleared {$clearedLocks} expired rollback locks");
        }

        $clearedCooldowns = $this->plugin->getPlayerListener()->cleanupExpiredCooldowns();
        
        if ($clearedCooldowns > 0 && $this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("[MaintenanceTask] Cleaned {$clearedCooldowns} expired cooldowns");
        }

        if ($this->plugin->isDebugEnabled() && $this->plugin->getConfigValue("debug.log_cache_stats", false)) {
            $stats = $this->plugin->getDataManager()->getStats();
            $this->plugin->getLogger()->debug(
                "[MaintenanceTask] Cache Stats - Hit Rate: {$stats['cache_hit_rate']}, " .
                "Size: {$stats['cache_size']}/{$this->plugin->getConfigValue('performance.cache_max_size', 100)}"
            );
        }
    }
}