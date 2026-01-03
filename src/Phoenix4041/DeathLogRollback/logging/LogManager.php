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
    private bool $structuredLogging;
    private bool $rotationEnabled;
    private int $maxLogSizeMB;
    private bool $debugEnabled;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
        $this->structuredLogging = $plugin->getConfigValue("logging.structured_logging", true);
        $this->rotationEnabled = $plugin->getConfigValue("logging.log_rotation_enabled", true);
        $this->maxLogSizeMB = $plugin->getConfigValue("logging.max_log_file_size_mb", 10);
        $this->debugEnabled = $plugin->getConfigValue("debug.enabled", false);
        
        $logPath = $plugin->getDataFolder() . "rollback_log.yml";
        
        if ($this->rotationEnabled) {
            $this->checkAndRotateLog($logPath);
        }
        
        $this->rollbackLog = new Config($logPath, Config::YAML, [
            "next_log_id" => 1,
            "logs" => [],
            "metadata" => [
                "created_at" => time(),
                "version" => "2.1.0"
            ]
        ]);

        $this->logIdCounter = $this->rollbackLog->get("next_log_id", 1);
    }

    private function checkAndRotateLog(string $logPath): void {
        if (!file_exists($logPath)) {
            return;
        }

        $fileSizeMB = filesize($logPath) / 1048576;
        
        if ($fileSizeMB > $this->maxLogSizeMB) {
            $timestamp = date("Y-m-d_His");
            $rotatedPath = $this->plugin->getDataFolder() . "rollback_log_{$timestamp}.yml";
            
            if (rename($logPath, $rotatedPath)) {
                $this->plugin->getLogger()->info("Log file rotated: {$rotatedPath}");
                $this->debugLog("Rotated log file due to size: {$fileSizeMB}MB");
            }
        }
    }

    public function logRollback(string $adminName, string $targetName, int $deathId, int $timestamp): void {
        if (!$this->plugin->getConfigValue("logging.log_local_enabled", true)) {
            return;
        }

        $logId = "LOG_ID_" . str_pad((string)$this->logIdCounter, 4, "0", STR_PAD_LEFT);
        
        $logEntry = $this->structuredLogging ? [
            "log_id" => $logId,
            "restored_by" => $adminName,
            "target_player" => $targetName,
            "death_id" => $deathId,
            "timestamp" => $timestamp,
            "formatted_time" => $this->plugin->formatTimestamp($timestamp),
            "server_time" => time()
        ] : [
            "restored_by" => $adminName,
            "target_player" => $targetName,
            "death_id" => $deathId,
            "timestamp" => $this->plugin->formatTimestamp($timestamp)
        ];
        
        $logs = $this->rollbackLog->get("logs", []);
        $logs[$logId] = $logEntry;

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

        $this->debugLog("Logged rollback: {$logId} - {$adminName} -> {$targetName} (Death: {$deathId})");
    }

    public function sendWebhookNotification(string $adminName, string $targetName, int $deathId, array $coords): void {
        if (!$this->plugin->getConfigValue("logging.webhook_enabled", false)) {
            $this->debugLog("Webhook disabled, skipping notification");
            return;
        }

        $webhookUrl = $this->plugin->getConfigValue("logging.webhook_url", "");
        
        if (empty($webhookUrl)) {
            $this->debugLog("Webhook URL not configured");
            return;
        }

        $embed = [
            "embeds" => [
                [
                    "title" => "Rollback Ejecutado",
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
                        "text" => "DeathLogRollback v2.1"
                    ],
                    "timestamp" => date("c")
                ]
            ]
        ];

        try {
            Internet::postURL($webhookUrl, [
                "Content-Type" => "application/json"
            ], json_encode($embed));

            $this->debugLog("Webhook notification sent successfully");
        } catch (\Exception $e) {
            $this->plugin->getLogger()->warning("Failed to send webhook: " . $e->getMessage());
            $this->debugLog("Webhook exception: " . $e->getMessage());
        }
    }

    public function getLogStats(): array {
        $logs = $this->rollbackLog->get("logs", []);
        
        return [
            "total_rollbacks" => count($logs),
            "next_log_id" => $this->logIdCounter,
            "structured_logging" => $this->structuredLogging,
            "rotation_enabled" => $this->rotationEnabled
        ];
    }

    public function saveLog(): void {
        try {
            $this->rollbackLog->save();
            $this->debugLog("Log file saved successfully");
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Failed to save log: " . $e->getMessage());
        }
    }

    private function debugLog(string $message): void {
        if ($this->debugEnabled) {
            $this->plugin->getLogger()->debug("[LogManager] " . $message);
        }
    }
}