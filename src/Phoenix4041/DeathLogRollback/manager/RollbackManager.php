<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\manager;

use pocketmine\player\Player;
use pocketmine\entity\object\ItemEntity;
use pocketmine\math\Vector3;
use Phoenix4041\DeathLogRollback\Loader;
use Phoenix4041\DeathLogRollback\utils\ItemSerializer;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use pocketmine\scheduler\ClosureTask;
use pocketmine\item\Item;
use pocketmine\block\tile\Container;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;

class RollbackManager {

    private Loader $plugin;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
    }

    public function executeRollback(Player $admin, int $globalId): bool {
        $recordInfo = $this->plugin->getDataManager()->getRecordByGlobalId($globalId);
        
        if ($recordInfo === null) {
            $admin->sendMessage($this->plugin->getMessage("errors.record_not_found", ["id" => $globalId]));
            return false;
        }

        $targetRecord = $recordInfo['record'];
        $targetUUID = $recordInfo['uuid']; 

        try {
            $uuidObject = Uuid::fromString($targetUUID); 
        } catch (\InvalidArgumentException $e) {
            $this->plugin->getLogger()->error("Invalid UUID {$targetUUID}: " . $e->getMessage());
            $admin->sendMessage($this->plugin->getMessage("errors.invalid_uuid_data"));
            return false;
        }
        
        $targetPlayer = $this->plugin->getServer()->getPlayerByUuid($uuidObject); 
        
        if ($targetPlayer === null || !$targetPlayer->isOnline()) {
            $admin->sendMessage($this->plugin->getMessage("errors.player_offline"));
            return false;
        }

        $admin->sendMessage($this->plugin->getMessage("rollback.processing", ["id" => $globalId]));

        $deathData = $targetRecord["data"];
        $deathItems = $this->deserializeDeathItems($deathData);
        
        if (empty($deathItems)) {
            $admin->sendMessage("§cNo items to restore.");
            return false;
        }

        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($admin, $targetPlayer, $deathData, $targetUUID, $globalId, $deathItems): void {
            if (!$targetPlayer->isOnline()) {
                $admin->sendMessage($this->plugin->getMessage("errors.player_offline"));
                return;
            }
            
            $stats = $this->performTargetedCleanup($globalId, $deathItems, $admin);
            
            $admin->sendMessage("§a╔══════════════════════════════════════╗");
            $admin->sendMessage("§a║    §6Anti-Dupe Cleanup Results§a       ║");
            $admin->sendMessage("§a╠══════════════════════════════════════╣");
            
            if (!empty($stats['suspects'])) {
                $admin->sendMessage("§a║ §cSuspects found:");
                foreach ($stats['suspects'] as $suspectName => $suspectInfo) {
                    $total = $suspectInfo['inventory'] + $suspectInfo['enderchest'] + $suspectInfo['containers'];
                    $admin->sendMessage("§a║   §f{$suspectName}: §e{$total} items §7(Inv:{$suspectInfo['inventory']} EC:{$suspectInfo['enderchest']} Chest:{$suspectInfo['containers']})");
                }
                $admin->sendMessage("§a║ §c━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            }
            
            $admin->sendMessage("§a║ §fTracked entities:  §e{$stats['tracked']}");
            $admin->sendMessage("§a║ §fPlayer inventories: §e{$stats['players']}");
            $admin->sendMessage("§a║ §fEnder chests:      §e{$stats['enderchests']}");
            $admin->sendMessage("§a║ §fContainers:        §e{$stats['containers']}");
            $admin->sendMessage("§a║ §c━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $admin->sendMessage("§a║ §6Total removed:     §c{$stats['total']} items");
            $admin->sendMessage("§a╚══════════════════════════════════════╝");
            
            $this->completeRollbackSequence($admin, $targetPlayer, $deathData, $targetUUID, $globalId);
        }), 10);

        return true;
    }

    /**
     * SISTEMA ULTRA PRECISO: Solo limpia a los sospechosos detectados
     */
    private function performTargetedCleanup(int $deathId, array &$deathItems, Player $admin): array {
        $stats = [
            'tracked' => 0,
            'players' => 0,
            'containers' => 0,
            'enderchests' => 0,
            'total' => 0,
            'suspects' => []
        ];

        // 1. Elimina items del suelo (entities)
        $stats['tracked'] = $this->removeTrackedItemsGlobally($deathId);

        // 2. Obtiene SOLO los sospechosos (killer + quien recogió items)
        $suspects = $this->plugin->getDeathTracker()->getSuspectsForDeath($deathId);

        if (empty($suspects)) {
            $admin->sendMessage("§e[Anti-Dupe] No suspects identified for this death");
            $admin->sendMessage("§7This usually means items weren't picked up yet or tracking expired");
            return $stats;
        }

        $admin->sendMessage("§e[Anti-Dupe] Found " . count($suspects) . " suspect(s), scanning their items...");

        // 3. Por cada sospechoso, busca EN TODO SU INVENTARIO
        foreach ($suspects as $suspectUuid => $suspectData) {
            $suspectPlayer = $this->plugin->getServer()->getPlayerByRawUUID($suspectUuid);
            $suspectName = $suspectPlayer ? $suspectPlayer->getName() : "Unknown";
            
            $suspectStats = [
                'inventory' => 0,
                'enderchest' => 0,
                'containers' => 0
            ];

            // Solo si está online, limpia sus inventarios
            if ($suspectPlayer !== null && $suspectPlayer->isOnline()) {
                $invRemoved = $this->cleanupSuspectInventory($suspectPlayer, $deathItems);
                $ecRemoved = $this->cleanupSuspectEnderChest($suspectPlayer, $deathItems);
                
                $suspectStats['inventory'] = $invRemoved;
                $suspectStats['enderchest'] = $ecRemoved;
                
                $stats['players'] += $invRemoved;
                $stats['enderchests'] += $ecRemoved;
                
                if ($invRemoved > 0) {
                    $admin->sendMessage("§c[Anti-Dupe] Removed {$invRemoved} items from {$suspectName}'s inventory");
                }
                
                if ($ecRemoved > 0) {
                    $admin->sendMessage("§c[Anti-Dupe] Removed {$ecRemoved} items from {$suspectName}'s EnderChest");
                }
            } else {
                $admin->sendMessage("§7[Anti-Dupe] Suspect {$suspectName} is offline, skipping inventory check");
            }

            // Busca en TODOS los cofres de TODOS los mundos
            $containerRemoved = $this->cleanupSuspectContainers($suspectUuid, $deathItems, $suspectName, $admin);
            $suspectStats['containers'] = $containerRemoved;
            $stats['containers'] += $containerRemoved;

            // Solo agrega a la lista si encontró algo
            $totalRemoved = $suspectStats['inventory'] + $suspectStats['enderchest'] + $suspectStats['containers'];
            if ($totalRemoved > 0) {
                $stats['suspects'][$suspectName] = $suspectStats;
            }
        }

        $stats['total'] = $stats['tracked'] + $stats['players'] + $stats['containers'] + $stats['enderchests'];

        return $stats;
    }

    private function removeTrackedItemsGlobally(int $deathId): int {
        $removed = 0;
        $trackedItems = $this->plugin->getDeathTracker()->getTrackedItemsForDeath($deathId);

        foreach ($trackedItems as $entityId => $itemData) {
            $entity = $itemData['entity'];
            if ($entity instanceof ItemEntity && !$entity->isClosed()) {
                $entity->flagForDespawn();
                $entity->close();
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->plugin->getLogger()->info("§c[Anti-Dupe] Removed {$removed} items from ground");
        }

        return $removed;
    }

    /**
     * Limpia inventario completo del sospechoso
     */
    private function cleanupSuspectInventory(Player $suspect, array &$deathItems): int {
        $removed = 0;
        
        $removed += $this->removeItemsFromInventory($suspect->getInventory(), $deathItems);
        $removed += $this->removeItemsFromInventory($suspect->getArmorInventory(), $deathItems);
        $removed += $this->removeItemsFromInventory($suspect->getCursorInventory(), $deathItems);
        $removed += $this->removeItemsFromInventory($suspect->getOffHandInventory(), $deathItems);

        return $removed;
    }

    /**
     * MEJORADO: Limpia EnderChest del sospechoso con logging detallado
     */
    private function cleanupSuspectEnderChest(Player $suspect, array &$deathItems): int {
        $enderInventory = $suspect->getEnderInventory();
        
        // Debug: Contar items antes
        $itemsBefore = 0;
        foreach ($enderInventory->getContents() as $item) {
            if (!$item->isNull()) {
                $itemsBefore++;
            }
        }
        
        if ($itemsBefore > 0) {
            $this->plugin->getLogger()->info("§e[Debug] {$suspect->getName()} has {$itemsBefore} items in EnderChest before cleanup");
        }
        
        // Ejecuta limpieza
        $removed = $this->removeItemsFromInventory($enderInventory, $deathItems);
        
        if ($removed > 0) {
            $this->plugin->getLogger()->info("§a[Debug] Removed {$removed} duped items from {$suspect->getName()}'s EnderChest");
            $suspect->sendMessage("§c[Anti-Dupe] {$removed} duped items were removed from your EnderChest");
        }
        
        return $removed;
    }

    /**
     * MEJORADO: Busca en TODOS los cofres de TODOS los mundos
     * Pero solo cuenta los items que pertenecen a este sospechoso
     */
    private function cleanupSuspectContainers(string $suspectUuid, array &$deathItems, string $suspectName, Player $admin): int {
        $removed = 0;
        $processedPositions = [];
        $foundChests = [];

        foreach ($this->plugin->getServer()->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getLoadedChunks() as $chunk) {
                foreach ($chunk->getTiles() as $tile) {
                    if (!($tile instanceof Container)) continue;

                    $tilePos = $tile->getPosition();
                    $posKey = $world->getFolderName() . ":" . 
                              (int)$tilePos->x . ":" . 
                              (int)$tilePos->y . ":" . 
                              (int)$tilePos->z;
                    
                    if (isset($processedPositions[$posKey])) continue;
                    $processedPositions[$posKey] = true;

                    $inventory = $tile->getInventory();
                    if ($inventory === null) continue;

                    $beforeCount = count($deathItems);
                    $removedFromThis = $this->removeItemsFromInventory($inventory, $deathItems);
                    $removed += $removedFromThis;

                    if ($removedFromThis > 0) {
                        $chestInfo = "{$world->getFolderName()} ({$tilePos->x}, {$tilePos->y}, {$tilePos->z})";
                        $foundChests[] = $chestInfo;
                        
                        $this->plugin->getLogger()->warning(
                            "§c[Anti-Dupe] Found {$removedFromThis} duped items in chest at {$chestInfo}"
                        );
                        $admin->sendMessage("§c[Anti-Dupe] Found items in chest at {$chestInfo}");
                    }
                }
            }
        }

        if ($removed > 0 && !empty($foundChests)) {
            $admin->sendMessage("§e[Anti-Dupe] Scanned chests in suspect {$suspectName}'s area:");
            foreach ($foundChests as $chest) {
                $admin->sendMessage("§7  - {$chest}");
            }
        }

        return $removed;
    }

    private function deserializeDeathItems(array $deathData): array {
        $items = [];

        if (isset($deathData["inventory"])) {
            $inventoryData = $deathData["inventory"];
            
            if ($this->isSequentialArray($inventoryData)) {
                foreach ($inventoryData as $itemString) {
                    if (is_string($itemString) && $itemString !== "") {
                        $item = ItemSerializer::deserialize($itemString);
                        if ($item !== null && !$item->isNull()) {
                            $items[] = $item;
                        }
                    }
                }
            } else {
                foreach ($inventoryData as $slot => $itemString) {
                    if (is_string($itemString) && $itemString !== "") {
                        $item = ItemSerializer::deserialize($itemString);
                        if ($item !== null && !$item->isNull()) {
                            $items[] = $item;
                        }
                    }
                }
            }
        }

        if (isset($deathData["armor"])) {
            foreach ($deathData["armor"] as $armorSlot => $itemString) {
                if (is_string($itemString) && $itemString !== "") {
                    $item = ItemSerializer::deserialize($itemString);
                    if ($item !== null && !$item->isNull()) {
                        $items[] = $item;
                    }
                }
            }
        }

        return $items;
    }

    private function isSequentialArray(array $arr): bool {
        if (empty($arr)) return false;
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function areItemsIdentical(Item $item1, Item $item2): bool {
        if ($item1->getTypeId() !== $item2->getTypeId()) return false;
        if ($item1->getCount() !== $item2->getCount()) return false;

        $hasCustom1 = $item1->hasCustomName() || !empty($item1->getLore()) || !empty($item1->getEnchantments());
        $hasCustom2 = $item2->hasCustomName() || !empty($item2->getLore()) || !empty($item2->getEnchantments());

        if (!$hasCustom1 && !$hasCustom2) return true;

        try {
            $serializer = new BigEndianNbtSerializer();
            $nbt1 = $item1->nbtSerialize();
            $nbt2 = $item2->nbtSerialize();
            $serialized1 = $serializer->write(new TreeRoot($nbt1));
            $serialized2 = $serializer->write(new TreeRoot($nbt2));
            return $serialized1 === $serialized2;
        } catch (\Exception $e) {
            if ($item1->hasCustomName() !== $item2->hasCustomName()) return false;
            if ($item1->hasCustomName() && $item1->getCustomName() !== $item2->getCustomName()) return false;
            if ($item1->getLore() !== $item2->getLore()) return false;
            
            $enchants1 = $item1->getEnchantments();
            $enchants2 = $item2->getEnchantments();
            if (count($enchants1) !== count($enchants2)) return false;
            
            foreach ($enchants1 as $enchant1) {
                $found = false;
                foreach ($enchants2 as $enchant2) {
                    if ($enchant1->getType() === $enchant2->getType() && $enchant1->getLevel() === $enchant2->getLevel()) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) return false;
            }
            return true;
        }
    }

    private function removeItemsFromInventory($inventory, array &$deathItems): int {
        $removed = 0;
        $slotsToCheck = [];
        
        foreach ($inventory->getContents() as $slot => $item) {
            if (!$item->isNull()) {
                $slotsToCheck[$slot] = $item;
            }
        }

        foreach ($slotsToCheck as $slot => $item) {
            foreach ($deathItems as $index => $deathItem) {
                if ($this->areItemsIdentical($item, $deathItem)) {
                    $inventory->clear($slot);
                    $removed++;
                    unset($deathItems[$index]);
                    break;
                }
            }
        }

        return $removed;
    }

    private function completeRollbackSequence(Player $admin, Player $targetPlayer, array $deathData, string $targetUUID, int $globalId): void {
        if (!$targetPlayer->isOnline()) {
            $admin->sendMessage($this->plugin->getMessage("errors.player_offline"));
            return;
        }

        $inventory = $targetPlayer->getInventory();
        $armorInventory = $targetPlayer->getArmorInventory();
        
        $inventory->clearAll();
        $armorInventory->clearAll();
        
        try {
            $success = $this->restoreInventory($targetPlayer, $deathData);

            if (!$success) {
                $admin->sendMessage($this->plugin->getMessage("errors.deserialization_failed"));
                return;
            }

            $this->plugin->getDataManager()->deleteDeathRecord($targetUUID, $globalId);
            $this->plugin->getDeathTracker()->untrackDeath($globalId);

            $deathCoords = $deathData["coords"];
            
            $this->plugin->getLogManager()->logRollback(
                $admin->getName(),
                $targetPlayer->getName(),
                $globalId,
                time()
            );

            $this->plugin->getLogManager()->sendWebhookNotification(
                $admin->getName(),
                $targetPlayer->getName(),
                $globalId,
                $deathCoords
            );
            
            $admin->sendMessage($this->plugin->getMessage("rollback.success", ["id" => $globalId]));
            $targetPlayer->sendMessage($this->plugin->getMessage("rollback.restored_notification", ["admin" => $admin->getName()]));

        } catch (\Exception $e) {
            $admin->sendMessage($this->plugin->getMessage("errors.unexpected_error"));
            $this->plugin->getLogger()->error("Rollback failed for ID {$globalId}: " . $e->getMessage());
        }
    }

    private function restoreInventory(Player $player, array $deathData): bool {
        $inventory = $player->getInventory();
        $armorInventory = $player->getArmorInventory();

        try {
            if (isset($deathData["inventory"])) {
                foreach ($deathData["inventory"] as $slot => $itemString) {
                    if (is_string($itemString) && $itemString !== "") {
                        $item = ItemSerializer::deserialize($itemString);
                        if ($item !== null && !$item->isNull()) {
                            $inventory->setItem((int)$slot, $item);
                        }
                    }
                }
            }

            if (isset($deathData["armor"])) {
                $armorData = $deathData["armor"];
                
                if (isset($armorData["helmet"]) && $armorData["helmet"] !== "") {
                    $item = ItemSerializer::deserialize($armorData["helmet"]);
                    if ($item !== null && !$item->isNull()) {
                        $armorInventory->setHelmet($item);
                    }
                }
                
                if (isset($armorData["chestplate"]) && $armorData["chestplate"] !== "") {
                    $item = ItemSerializer::deserialize($armorData["chestplate"]);
                    if ($item !== null && !$item->isNull()) {
                        $armorInventory->setChestplate($item);
                    }
                }
                
                if (isset($armorData["leggings"]) && $armorData["leggings"] !== "") {
                    $item = ItemSerializer::deserialize($armorData["leggings"]);
                    if ($item !== null && !$item->isNull()) {
                        $armorInventory->setLeggings($item);
                    }
                }
                
                if (isset($armorData["boots"]) && $armorData["boots"] !== "") {
                    $item = ItemSerializer::deserialize($armorData["boots"]);
                    if ($item !== null && !$item->isNull()) {
                        $armorInventory->setBoots($item);
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Failed to restore inventory for {$player->getName()}: " . $e->getMessage());
            return false;
        }
    }
}