<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\data;

use pocketmine\utils\Config;
use Phoenix4041\DeathLogRollback\Loader;

class DataManager {
    
    private Loader $plugin;
    private Config $globalData;
    private Config $playerDeaths;
    private int $maxRecordsPerPlayer;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
        $this->maxRecordsPerPlayer = $plugin->getConfigValue("max_records_per_player", 10);

        $dataPath = $plugin->getDataFolder() . "data/";

        if (!is_dir($dataPath)) {
            mkdir($dataPath, 0777, true);
        }

        $this->globalData = new Config($dataPath . "global_data.yml", Config::YAML, [
            "next_id" => 1
        ]);

        $this->playerDeaths = new Config($dataPath . "player_deaths.yml", Config::YAML, []);
    }

    public function getNextGlobalId(): int {
        $currentId = $this->globalData->get("next_id", 1);
        $this->globalData->set("next_id", $currentId + 1);
        $this->globalData->save();
        return $currentId;
    }

    public function addDeathRecord(string $playerName, string $playerUUID, array $deathData): int {
        $globalId = $this->getNextGlobalId();
        $playerHistory = $this->playerDeaths->get($playerUUID, []);

        $record = [
            "id" => $globalId,
            "player_name" => $playerName,
            "uuid" => $playerUUID,
            "timestamp" => $deathData["timestamp"] ?? time(),
            "data" => $deathData
        ];

        $playerHistory[] = $record;

        // Lógica de Ventana Deslizante (Ring Buffer)
        if (count($playerHistory) > $this->maxRecordsPerPlayer) {
            // Ordenar por ID ascendente para encontrar el más antiguo
            usort($playerHistory, fn($a, $b) => $a["id"] <=> $b["id"]); 
            // Eliminar el primer elemento (el más antiguo)
            array_shift($playerHistory); 
        }

        $this->playerDeaths->set($playerUUID, $playerHistory);
        $this->playerDeaths->save();

        return $globalId;
    }

    public function getPlayerDeaths(string $playerUUID): ?array {
        $history = $this->playerDeaths->get($playerUUID, null);
        
        if ($history === null || empty($history)) {
            return null;
        }

        // Devolver ordenado de forma descendente (más reciente primero) para los formularios
        usort($history, fn($a, $b) => $b["id"] <=> $a["id"]); 
        
        return $history;
    }
    
    /**
     * @param string $playerUUID Es redundante si se usa getRecordByGlobalId, 
     * pero se mantiene por consistencia si se usa en otros flujos.
     */
    public function getDeathRecord(string $playerUUID, int $globalId): ?array {
        $history = $this->playerDeaths->get($playerUUID, null);
        
        if ($history === null) {
            return null;
        }

        foreach ($history as $record) {
            if ($record["id"] === $globalId) {
                return $record;
            }
        }

        return null;
    }
    
    // --- MÉTODO CORREGIDO FALTANTE (CAUSA DEL CRASHEO) ---
    /**
     * Busca un registro de muerte por su ID Global en TODOS los historiales de jugadores.
     * Requerido por RollbackManager para iniciar la restauración.
     * @param int $globalId La ID Global a buscar.
     * @return array|null [ 'uuid' => string, 'record' => array ] si se encuentra, null en caso contrario.
     */
    public function getRecordByGlobalId(int $globalId): ?array {
        $allPlayersHistory = $this->playerDeaths->getAll();

        foreach ($allPlayersHistory as $uuid => $history) {
            if (!is_array($history)) continue;

            foreach ($history as $record) {
                if (isset($record['id']) && (int)$record['id'] === $globalId) {
                    return [
                        'uuid' => $uuid,
                        'record' => $record
                    ];
                }
            }
        }

        return null;
    }
    // --- FIN MÉTODO CORREGIDO ---


    public function deleteDeathRecord(string $playerUUID, int $globalId): bool {
        $history = $this->playerDeaths->get($playerUUID, []);
        
        if (empty($history)) {
            return false;
        }

        $initialCount = count($history);
        
        $history = array_filter($history, fn($record) => $record["id"] !== $globalId);

        if (count($history) === $initialCount) {
            return false; // No se encontró para eliminar
        }

        $history = array_values($history);

        $this->playerDeaths->set($playerUUID, $history);
        $this->playerDeaths->save();

        $this->plugin->getLogger()->info(
            $this->plugin->getMessage("logging.record_deleted", [
                "id" => $globalId,
                "uuid" => $playerUUID
            ])
        );

        return true;
    }

    public function getAllPlayerData(): array {
        return $this->playerDeaths->getAll();
    }

    public function getCurrentGlobalId(): int {
        return $this->globalData->get("next_id", 1) - 1;
    }

    public function saveAll(): void {
        $this->globalData->save();
        $this->playerDeaths->save();
        $this->plugin->getLogger()->info("§a[DataManager] All data saved successfully");
    }

    public function getStats(): array {
        $allPlayers = $this->playerDeaths->getAll();
        $totalRecords = 0;
        
        foreach ($allPlayers as $history) {
            $totalRecords += count($history);
        }

        return [
            "total_players" => count($allPlayers),
            "total_records" => $totalRecords,
            "next_global_id" => $this->globalData->get("next_id", 1),
            "max_records_per_player" => $this->maxRecordsPerPlayer
        ];
    }

    public function purgeOldRecords(int $maxAge): int {
        $cutoffTime = time() - $maxAge;
        $purgedCount = 0;
        $allPlayers = $this->playerDeaths->getAll();

        foreach ($allPlayers as $uuid => $history) {
            $initialCount = count($history);
            
            $filteredHistory = array_filter($history, fn($record) => $record["timestamp"] >= $cutoffTime);
            
            $purgedCount += ($initialCount - count($filteredHistory));

            if (empty($filteredHistory)) {
                $this->playerDeaths->remove($uuid);
            } else {
                $this->playerDeaths->set($uuid, array_values($filteredHistory));
            }
        }

        $this->playerDeaths->save();
        
        return $purgedCount;
    }
}