<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\data;

use pocketmine\utils\Config;
use Phoenix4041\DeathLogRollback\Loader;
use Ramsey\Uuid\Uuid;

class DataManager {
    
    private Loader $plugin;
    private Config $globalData;
    private Config $playerDeaths;
    private int $maxRecordsPerPlayer;
    private array $deathCache = [];
    private int $cacheMaxSize;
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private bool $cacheEnabled;
    private bool $debugEnabled;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
        $this->maxRecordsPerPlayer = $plugin->getConfigValue("max_records_per_player", 10);
        $this->cacheEnabled = $plugin->getConfigValue("performance.cache_enabled", true);
        $this->cacheMaxSize = $plugin->getConfigValue("performance.cache_max_size", 100);
        $this->debugEnabled = $plugin->getConfigValue("debug.enabled", false);

        $dataPath = $plugin->getDataFolder() . "data/";
        
        if (!is_dir($dataPath)) {
            mkdir($dataPath, 0755, true);
        }

        $this->globalData = new Config($dataPath . "global_data.yml", Config::YAML, [
            "next_id" => 1,
            "metadata" => [
                "version" => "2.1.0",
                "created_at" => time()
            ]
        ]);

        $this->playerDeaths = new Config($dataPath . "player_deaths.yml", Config::YAML, []);
        
        if ($this->cacheEnabled) {
            $this->buildCacheIndex();
        }
    }

    private function buildCacheIndex(): void {
        $allData = $this->playerDeaths->getAll();
        $indexedCount = 0;
        
        foreach ($allData as $uuid => $records) {
            if (!is_array($records)) continue;
            
            foreach ($records as $record) {
                if (!isset($record['id'])) continue;
                
                $globalId = (int)$record['id'];
                $this->deathCache[$globalId] = [
                    'uuid' => $uuid,
                    'record' => $record,
                    'access_time' => time()
                ];
                $indexedCount++;
            }
        }
        
        $this->debugLog("Cache index built: {$indexedCount} records indexed");
    }

    public function getNextGlobalId(): int {
        $currentId = $this->globalData->get("next_id", 1);
        
        if (!is_int($currentId) || $currentId < 1) {
            $this->plugin->getLogger()->warning("Invalid next_id detected, resetting to 1");
            $currentId = 1;
        }
        
        $this->globalData->set("next_id", $currentId + 1);
        $this->globalData->save();
        
        return $currentId;
    }

    public function addDeathRecord(string $playerName, string $playerUUID, array $deathData): int {
        try {
            $uuid = Uuid::fromString($playerUUID);
            $normalizedUUID = $uuid->toString();
        } catch (\InvalidArgumentException $e) {
            $this->plugin->getLogger()->error("Invalid UUID format: {$playerUUID}");
            throw new \InvalidArgumentException("Invalid UUID format");
        }

        $this->validateDeathData($deathData);

        $globalId = $this->getNextGlobalId();
        $playerHistory = $this->playerDeaths->get($normalizedUUID, []);

        $record = [
            "id" => $globalId,
            "player_name" => $playerName,
            "uuid" => $normalizedUUID,
            "timestamp" => $deathData["timestamp"] ?? time(),
            "data" => $deathData,
            "version" => "2.1.0"
        ];

        $playerHistory[] = $record;

        if (count($playerHistory) > $this->maxRecordsPerPlayer) {
            usort($playerHistory, fn($a, $b) => ($a["timestamp"] ?? 0) <=> ($b["timestamp"] ?? 0));
            $removed = array_shift($playerHistory);
            
            if (isset($removed['id']) && $this->cacheEnabled) {
                unset($this->deathCache[(int)$removed['id']]);
                $this->debugLog("Removed old record from cache: ID {$removed['id']}");
            }
        }

        $this->playerDeaths->set($normalizedUUID, $playerHistory);
        $this->playerDeaths->save();

        if ($this->cacheEnabled) {
            $this->updateCache($globalId, $normalizedUUID, $record);
        }

        $this->debugLog("Added death record: ID {$globalId} for player {$playerName}");

        return $globalId;
    }

    private function validateDeathData(array $data): void {
        $required = ['inventory', 'armor', 'coords', 'cause'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!isset($data['coords']['x'], $data['coords']['y'], $data['coords']['z'], $data['coords']['world'])) {
            throw new \InvalidArgumentException("Invalid coords structure");
        }
    }

    public function getPlayerDeaths(string $playerUUID): ?array {
        try {
            $uuid = Uuid::fromString($playerUUID);
            $normalizedUUID = $uuid->toString();
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        $history = $this->playerDeaths->get($normalizedUUID, null);
        
        if ($history === null || empty($history)) {
            return null;
        }

        usort($history, fn($a, $b) => ($b["timestamp"] ?? 0) <=> ($a["timestamp"] ?? 0));
        
        return $history;
    }
    
    public function getDeathRecord(string $playerUUID, int $globalId): ?array {
        if ($this->cacheEnabled && isset($this->deathCache[$globalId])) {
            $cached = $this->deathCache[$globalId];
            
            try {
                $uuid = Uuid::fromString($playerUUID);
                if ($cached['uuid'] === $uuid->toString()) {
                    $this->cacheHits++;
                    $this->deathCache[$globalId]['access_time'] = time();
                    $this->debugLog("Cache hit for record ID {$globalId}");
                    return $cached['record'];
                }
            } catch (\InvalidArgumentException $e) {
                return null;
            }
        }

        $this->cacheMisses++;
        
        try {
            $uuid = Uuid::fromString($playerUUID);
            $normalizedUUID = $uuid->toString();
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        $history = $this->playerDeaths->get($normalizedUUID, null);
        
        if ($history === null) {
            return null;
        }

        foreach ($history as $record) {
            if (isset($record["id"]) && (int)$record["id"] === $globalId) {
                if ($this->cacheEnabled) {
                    $this->updateCache($globalId, $normalizedUUID, $record);
                }
                return $record;
            }
        }

        return null;
    }
    
    public function getRecordByGlobalId(int $globalId): ?array {
        if ($globalId <= 0) {
            return null;
        }

        if ($this->cacheEnabled && isset($this->deathCache[$globalId])) {
            $this->cacheHits++;
            $this->deathCache[$globalId]['access_time'] = time();
            $this->debugLog("Cache hit for global ID {$globalId}");
            
            return [
                'uuid' => $this->deathCache[$globalId]['uuid'],
                'record' => $this->deathCache[$globalId]['record']
            ];
        }

        $this->cacheMisses++;
        $this->debugLog("Cache miss for global ID {$globalId}, performing full scan");

        $allPlayersHistory = $this->playerDeaths->getAll();

        foreach ($allPlayersHistory as $uuid => $history) {
            if (!is_array($history)) continue;

            foreach ($history as $record) {
                if (isset($record['id']) && (int)$record['id'] === $globalId) {
                    if ($this->cacheEnabled) {
                        $this->updateCache($globalId, $uuid, $record);
                    }
                    
                    return [
                        'uuid' => $uuid,
                        'record' => $record
                    ];
                }
            }
        }

        return null;
    }

    private function updateCache(int $globalId, string $uuid, array $record): void {
        if (!$this->cacheEnabled) {
            return;
        }

        if (count($this->deathCache) >= $this->cacheMaxSize) {
            $oldest = null;
            $oldestTime = PHP_INT_MAX;
            
            foreach ($this->deathCache as $id => $entry) {
                if ($entry['access_time'] < $oldestTime) {
                    $oldestTime = $entry['access_time'];
                    $oldest = $id;
                }
            }
            
            if ($oldest !== null) {
                unset($this->deathCache[$oldest]);
                $this->debugLog("Evicted cache entry: ID {$oldest} (LRU)");
            }
        }

        $this->deathCache[$globalId] = [
            'uuid' => $uuid,
            'record' => $record,
            'access_time' => time()
        ];

        $this->debugLog("Updated cache for ID {$globalId}");
    }

    public function deleteDeathRecord(string $playerUUID, int $globalId): bool {
        try {
            $uuid = Uuid::fromString($playerUUID);
            $normalizedUUID = $uuid->toString();
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $history = $this->playerDeaths->get($normalizedUUID, []);
        
        if (empty($history)) {
            return false;
        }

        $initialCount = count($history);
        
        $history = array_filter($history, fn($record) => 
            !isset($record["id"]) || (int)$record["id"] !== $globalId
        );

        if (count($history) === $initialCount) {
            return false;
        }

        $history = array_values($history);

        $this->playerDeaths->set($normalizedUUID, $history);
        $this->playerDeaths->save();

        if ($this->cacheEnabled) {
            unset($this->deathCache[$globalId]);
        }

        $this->plugin->getLogger()->info("Record deleted - ID: {$globalId}, UUID: {$normalizedUUID}");

        return true;
    }

    public function getAllPlayerData(): array {
        return $this->playerDeaths->getAll();
    }

    public function getCurrentGlobalId(): int {
        $nextId = $this->globalData->get("next_id", 1);
        return max(1, $nextId - 1);
    }

    public function saveAll(): void {
        try {
            $this->globalData->save();
            $this->playerDeaths->save();
            
            $this->plugin->getLogger()->info("All data saved successfully");
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Failed to save data: " . $e->getMessage());
        }
    }

    public function getStats(): array {
        $allPlayers = $this->playerDeaths->getAll();
        $totalRecords = 0;
        
        foreach ($allPlayers as $history) {
            if (is_array($history)) {
                $totalRecords += count($history);
            }
        }

        $cacheTotal = $this->cacheHits + $this->cacheMisses;
        $cacheHitRate = $cacheTotal > 0 ? ($this->cacheHits / $cacheTotal) * 100 : 0;

        return [
            "total_players" => count($allPlayers),
            "total_records" => $totalRecords,
            "next_global_id" => $this->globalData->get("next_id", 1),
            "max_records_per_player" => $this->maxRecordsPerPlayer,
            "cache_enabled" => $this->cacheEnabled,
            "cache_size" => count($this->deathCache),
            "cache_hits" => $this->cacheHits,
            "cache_misses" => $this->cacheMisses,
            "cache_hit_rate" => round($cacheHitRate, 2) . "%"
        ];
    }

    public function purgeOldRecords(int $maxAge): int {
        $cutoffTime = time() - $maxAge;
        $purgedCount = 0;
        $allPlayers = $this->playerDeaths->getAll();

        foreach ($allPlayers as $uuid => $history) {
            if (!is_array($history)) continue;
            
            $initialCount = count($history);
            
            $filteredHistory = array_filter($history, function($record) use ($cutoffTime) {
                $timestamp = $record["timestamp"] ?? 0;
                $isValid = $timestamp >= $cutoffTime;
                
                if (!$isValid && isset($record['id']) && $this->cacheEnabled) {
                    unset($this->deathCache[(int)$record['id']]);
                }
                
                return $isValid;
            });
            
            $purgedCount += ($initialCount - count($filteredHistory));

            if (empty($filteredHistory)) {
                $this->playerDeaths->remove($uuid);
            } else {
                $this->playerDeaths->set($uuid, array_values($filteredHistory));
            }
        }

        $this->playerDeaths->save();
        
        $this->debugLog("Purged {$purgedCount} old records");
        
        return $purgedCount;
    }

    private function debugLog(string $message): void {
        if ($this->debugEnabled && $this->plugin->getConfigValue("debug.log_cache_stats", false)) {
            $this->plugin->getLogger()->debug("[DataManager] " . $message);
        }
    }

    public function rebuildCache(): void {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->deathCache = [];
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->buildCacheIndex();
        
        $this->plugin->getLogger()->info("Cache rebuilt successfully");
    }
}