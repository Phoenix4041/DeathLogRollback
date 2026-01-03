<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\manager;

use Phoenix4041\DeathLogRollback\Loader;
use pocketmine\entity\object\ItemEntity;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\item\Item;

class DeathTracker {

    private Loader $plugin;
    private array $trackedDeaths = [];
    private array $droppedItems = [];
    private array $itemOwnership = [];
    private const TRACK_TIME_WINDOW = 600; // 10 minutos

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Registra una muerte y marca al asesino como sospechoso principal
     */
    public function trackDeath(string $victimUuid, int $deathId, array $deathData, ?string $killerUuid = null): void {
        $this->trackedDeaths[$deathId] = [
            'victim_uuid' => $victimUuid,
            'killer_uuid' => $killerUuid,
            'data' => $deathData,
            'timestamp' => microtime(true),
            'tracked_items' => [],
            'suspects' => []
        ];

        // Si hay asesino, es el sospechoso #1
        if ($killerUuid !== null) {
            $this->trackedDeaths[$deathId]['suspects'][$killerUuid] = [
                'reason' => 'killer',
                'picked_items' => []
            ];
            
            $killerPlayer = $this->plugin->getServer()->getPlayerByRawUUID($killerUuid);
            if ($killerPlayer) {
                $this->plugin->getLogger()->info("§e[Anti-Dupe] Killer tracked: {$killerPlayer->getName()} for death #{$deathId}");
            }
        }

        $this->cleanOldTracking();
    }

    /**
     * Crea un ID único basado en las propiedades del item
     */
    private function createItemHash(Item $item, Vector3 $position, float $spawnTime): string {
        // Usa: tipo + cantidad + posición + tiempo para crear un hash único
        $data = $item->getTypeId() . "_" . 
                $item->getCount() . "_" . 
                round($position->x, 2) . "_" . 
                round($position->y, 2) . "_" . 
                round($position->z, 2) . "_" . 
                round($spawnTime, 3);
        
        return md5($data);
    }

    /**
     * Crea un hash del item recogido para matching
     */
    private function createPickupHash(Item $item, float $pickupTime, int $deathId): string {
        // Busca en un radio de tiempo cercano
        $timeWindow = round($pickupTime, 1); // 100ms de ventana
        
        $data = $item->getTypeId() . "_" . 
                $item->getCount() . "_" . 
                $deathId . "_" .
                $timeWindow;
        
        return md5($data);
    }

    /**
     * Registra un item dropeado cerca de una muerte
     */
    public function registerDroppedItem(ItemEntity $itemEntity, Vector3 $position, float $spawnTime): void {
        $item = $itemEntity->getItem();
        
        $this->plugin->getLogger()->info("§6[DEBUG-REGISTER] Attempting to register item: " . 
            $item->getName() . " x" . $item->getCount() . 
            " | Tracked deaths: " . count($this->trackedDeaths));
        
        foreach ($this->trackedDeaths as $deathId => $deathInfo) {
            $deathTime = $deathInfo['timestamp'];
            $deathPos = new Vector3(
                $deathInfo['data']['coords']['x'],
                $deathInfo['data']['coords']['y'],
                $deathInfo['data']['coords']['z']
            );
            
            $timeDiff = abs($spawnTime - $deathTime);
            $distance = $position->distance($deathPos);

            $this->plugin->getLogger()->info("§6[DEBUG-REGISTER] Death #{$deathId}: " .
                "TimeDiff=" . round($timeDiff, 2) . "s, Distance=" . round($distance, 1) . " blocks");

            // Ventana de 2 segundos y 16 bloques de distancia
            if ($timeDiff <= 2.0 && $distance <= 16) {
                $itemHash = $this->createItemHash($item, $position, $spawnTime);
                
                $this->trackedDeaths[$deathId]['tracked_items'][$itemHash] = [
                    'entity' => $itemEntity,
                    'item' => clone $item, // Guardamos copia del item
                    'position' => $position,
                    'spawn_time' => $spawnTime,
                    'picked_by' => null,
                    'type_id' => $item->getTypeId(),
                    'count' => $item->getCount()
                ];
                
                $this->droppedItems[$itemHash] = [
                    'death_id' => $deathId,
                    'victim_uuid' => $deathInfo['victim_uuid'],
                    'spawn_time' => $spawnTime,
                    'item' => clone $item
                ];
                
                $this->plugin->getLogger()->info("§a[Tracker] ✓ Registered item drop: " . 
                    $item->getName() . " x" . $item->getCount() . 
                    " at death #{$deathId} (hash: " . substr($itemHash, 0, 8) . ")");
                
                break;
            }
        }
    }

    /**
     * Registra cuando un jugador recoge un item de una muerte
     * MEJORADO: Busca por similitud de item en lugar de entity ID
     */
    public function trackItemPickup(string $playerUuid, Item $pickedItem, float $pickupTime): void {
        $matched = false;
        
        $this->plugin->getLogger()->info("§e[DEBUG-TRACK] trackItemPickup called for item: " . 
            $pickedItem->getName() . " x" . $pickedItem->getCount() . 
            " | Tracked deaths: " . count($this->trackedDeaths));
        
        // Busca en todas las muertes trackeadas
        foreach ($this->trackedDeaths as $deathId => $deathInfo) {
            $timeSinceDeath = microtime(true) - $deathInfo['timestamp'];
            
            $this->plugin->getLogger()->info("§e[DEBUG-TRACK] Checking death #{$deathId}: " .
                "Time since death=" . round($timeSinceDeath, 1) . "s, " .
                "Tracked items=" . count($deathInfo['tracked_items']));
            
            // Solo busca en muertes recientes (últimos 30 segundos)
            if ($timeSinceDeath > 30) {
                $this->plugin->getLogger()->info("§e[DEBUG-TRACK] Death #{$deathId} too old, skipping");
                continue;
            }
            
            // Busca items similares en esta muerte
            foreach ($deathInfo['tracked_items'] as $itemHash => $itemData) {
                $this->plugin->getLogger()->info("§e[DEBUG-TRACK] Comparing with tracked item: " .
                    "TypeID={$itemData['type_id']}, Count={$itemData['count']}, PickedBy=" . 
                    ($itemData['picked_by'] ?? 'null'));
                
                // Si ya fue recogido, skip
                if ($itemData['picked_by'] !== null) {
                    $this->plugin->getLogger()->info("§e[DEBUG-TRACK] Item already picked, skipping");
                    continue;
                }
                
                // Verifica si el item es similar
                if ($itemData['type_id'] === $pickedItem->getTypeId() && 
                    $itemData['count'] === $pickedItem->getCount()) {
                    
                    $this->plugin->getLogger()->info("§a[DEBUG-TRACK] ✓ MATCH FOUND!");
                    
                    // MATCH! Marca como recogido
                    $this->trackedDeaths[$deathId]['tracked_items'][$itemHash]['picked_by'] = $playerUuid;
                    
                    // Agrega al jugador como sospechoso si no lo es ya
                    if (!isset($this->trackedDeaths[$deathId]['suspects'][$playerUuid])) {
                        $this->trackedDeaths[$deathId]['suspects'][$playerUuid] = [
                            'reason' => 'picked_items',
                            'picked_items' => []
                        ];
                        
                        $player = $this->plugin->getServer()->getPlayerByRawUUID($playerUuid);
                        if ($player) {
                            $this->plugin->getLogger()->info("§e[Anti-Dupe] New suspect: {$player->getName()} picked " . 
                                $pickedItem->getName() . " x" . $pickedItem->getCount() . " from death #{$deathId}");
                        }
                    }
                    
                    // Registra qué item específico recogió
                    $this->trackedDeaths[$deathId]['suspects'][$playerUuid]['picked_items'][] = $itemHash;
                    
                    // Mantiene registro de propiedad
                    if (!isset($this->itemOwnership[$playerUuid])) {
                        $this->itemOwnership[$playerUuid] = [];
                    }
                    
                    $this->itemOwnership[$playerUuid][] = [
                        'death_id' => $deathId,
                        'item_hash' => $itemHash,
                        'pickup_time' => $pickupTime
                    ];
                    
                    $matched = true;
                    break 2; // Sale de ambos loops
                }
            }
        }
        
        if (!$matched) {
            $this->plugin->getLogger()->warning("§c[Tracker] Item pickup NOT matched: " . 
                $pickedItem->getName() . " x" . $pickedItem->getCount() . 
                " (TypeID: " . $pickedItem->getTypeId() . ")");
        }
    }

    /**
     * Obtiene la lista de sospechosos para una muerte específica
     */
    public function getSuspectsForDeath(int $deathId): array {
        return $this->trackedDeaths[$deathId]['suspects'] ?? [];
    }

    /**
     * Obtiene todos los items trackeados de una muerte
     */
    public function getTrackedItemsForDeath(int $deathId): array {
        return $this->trackedDeaths[$deathId]['tracked_items'] ?? [];
    }

    /**
     * Verifica si un jugador es sospechoso
     */
    public function isPlayerSuspect(string $playerUuid, int $deathId): bool {
        if (!isset($this->trackedDeaths[$deathId])) {
            return false;
        }
        
        return isset($this->trackedDeaths[$deathId]['suspects'][$playerUuid]);
    }

    /**
     * Obtiene todas las muertes donde un jugador es sospechoso
     */
    public function getDeathIdsByPlayer(string $playerUuid): array {
        $deathIds = [];
        
        foreach ($this->trackedDeaths as $deathId => $deathInfo) {
            if (isset($deathInfo['suspects'][$playerUuid])) {
                $deathIds[] = $deathId;
            }
        }
        
        return $deathIds;
    }

    /**
     * Deja de trackear una muerte (después del rollback)
     */
    public function untrackDeath(int $deathId): void {
        if (isset($this->trackedDeaths[$deathId])) {
            // Limpia items dropeados
            foreach ($this->trackedDeaths[$deathId]['tracked_items'] as $itemHash => $itemData) {
                unset($this->droppedItems[$itemHash]);
            }

            // Limpia ownership de sospechosos
            foreach ($this->trackedDeaths[$deathId]['suspects'] as $suspectUuid => $suspectData) {
                if (isset($this->itemOwnership[$suspectUuid])) {
                    $this->itemOwnership[$suspectUuid] = array_filter(
                        $this->itemOwnership[$suspectUuid],
                        fn($item) => $item['death_id'] !== $deathId
                    );
                    
                    if (empty($this->itemOwnership[$suspectUuid])) {
                        unset($this->itemOwnership[$suspectUuid]);
                    }
                }
            }

            unset($this->trackedDeaths[$deathId]);
            $this->plugin->getLogger()->info("§a[Anti-Dupe] Untracked death #{$deathId}");
        }
    }

    /**
     * Limpia tracking antiguo (más de 10 minutos)
     */
    private function cleanOldTracking(): void {
        $currentTime = microtime(true);
        
        foreach ($this->trackedDeaths as $deathId => $deathInfo) {
            if (($currentTime - $deathInfo['timestamp']) > self::TRACK_TIME_WINDOW) {
                $this->plugin->getLogger()->info("§7[Anti-Dupe] Auto-cleaning old death #{$deathId}");
                $this->untrackDeath($deathId);
            }
        }
    }

    public function getDeathInfo(int $deathId): ?array {
        return $this->trackedDeaths[$deathId] ?? null;
    }

    public function getKillerUuid(int $deathId): ?string {
        return $this->trackedDeaths[$deathId]['killer_uuid'] ?? null;
    }
}   