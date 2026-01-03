<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\logging;

use pocketmine\utils\Config;
use pocketmine\utils\Internet;
use Phoenix4041\DeathLogRollback\Loader;

class LogManager {

    private Loader $plugin;
    private Config $rollbackLog;
    private int $logIdCounter;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
        
        $logPath = $plugin->getDataFolder() . "rollback_log.yml";
        $this->rollbackLog = new Config($logPath, Config::YAML, [
            "next_log_id" => 1,
            "logs" => []
        ]);

        $this->logIdCounter = $this->rollbackLog->get("next_log_id", 1);
    }

    /**
     * Log rollback action to local file
     */
    public function logRollback(string $adminName, string $targetName, int $deathId, int $timestamp): void {
        if (!$this->plugin->getConfigValue("logging.log_local_enabled", true)) {
            return;
        }

        $logId = "LOG_ID_" . str_pad((string)$this->logIdCounter, 4, "0", STR_PAD_LEFT);
        
        $logs = $this->rollbackLog->get("logs", []);
        $logs[$logId] = [
            "restored_by" => $adminName,
            "target_player" => $targetName,
            "death_id" => $deathId,
            "timestamp" => $this->plugin->formatTimestamp($timestamp)
        ];

        $this->rollbackLog->set("logs", $logs);
        $this->rollbackLog->set("next_log_id", $this->logIdCounter + 1);
        $this->rollbackLog->save();

        $this->logIdCounter++;

        $this->plugin->getLogger()->info(
            $this->plugin->getMessage("logging.rollback_logged", [
                "log_id" => $logId,
                "admin" => $adminName,
                "player" => $targetName,
                "death_id" => $deathId
            ])
        );
    }

    /**
     * Send webhook notification
     */
    public function sendWebhookNotification(string $adminName, string $targetName, int $deathId, array $coords): void {
        if (!$this->plugin->getConfigValue("logging.webhook_enabled", false)) {
            return;
        }

        $webhookUrl = $this->plugin->getConfigValue("logging.webhook_url", "");
        
        if (empty($webhookUrl)) {
            return;
        }

        $embed = [
            "embeds" => [
                [
                    "title" => "🔄 Rollback Ejecutado",
                    "color" => 3066993,
                    "fields" => [
                        [
                            "name" => "Administrador",
                            "value" => $adminName,
                            "inline" => true
                        ],
                        [
                            "name" => "Jugador Restaurado",
                            "value" => $targetName,
                            "inline" => true
                        ],
                        [
                            "name" => "ID de Muerte",
                            "value" => (string)$deathId,
                            "inline" => true
                        ],
                        [
                            "name" => "Coordenadas Originales",
                            "value" => "X: {$coords['x']}, Y: {$coords['y']}, Z: {$coords['z']}\nMundo: {$coords['world']}",
                            "inline" => false
                        ],
                        [
                            "name" => "Fecha",
                            "value" => date("d/m/Y H:i:s"),
                            "inline" => false
                        ]
                    ],
                    "footer" => [
                        "text" => "DeathLogRollback v2.0"
                    ]
                ]
            ]
        ];

        Internet::postURL($webhookUrl, [
            "Content-Type" => "application/json"
        ], json_encode($embed));
    }

    /**
     * Get rollback log statistics
     */
    public function getLogStats(): array {
        $logs = $this->rollbackLog->get("logs", []);
        
        return [
            "total_rollbacks" => count($logs),
            "next_log_id" => $this->logIdCounter
        ];
    }

    /**
     * Save all pending log data
     */
    public function saveLog(): void {
        $this->rollbackLog->save();
    }
}