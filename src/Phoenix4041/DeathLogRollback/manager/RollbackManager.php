<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\manager;

use pocketmine\player\Player;
use pocketmine\entity\object\ItemEntity;
use pocketmine\math\Vector3;
use pocketmine\math\AxisAlignedBB;
use pocketmine\item\Item;
use Phoenix4041\DeathLogRollback\Loader;
use Phoenix4041\DeathLogRollback\utils\ItemSerializer;
use Ramsey\Uuid\Uuid;
use pocketmine\scheduler\ClosureTask;

class RollbackManager {

    private Loader $plugin;
    private array $activeLocks = [];
    private array $rollbackCooldowns = [];
    private int $maxConcurrentRollbacks;
    private bool $debugEnabled;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
        $this->maxConcurrentRollbacks = $plugin->getConfigValue("anti_dupe.max_concurrent_rollbacks", 3);
        $this->debugEnabled = $plugin->getConfigValue("debug.enabled", false);
    }

    public function executeRollback(Player $admin, int $globalId): bool {
        if ($this->isOnCooldown($admin)) {
            $remaining = $this->getRemainingCooldown($admin);
            $admin->sendMessage($this->plugin->getMessage("rollback_cooldown", ["seconds" => $remaining]));
            return false;
        }

        if (count($this->activeLocks) >= $this->maxConcurrentRollbacks) {
            $admin->sendMessage($this->plugin->getMessage("errors.server_busy"));
            return false;
        }

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
            $this->debugLog("Invalid UUID stored: {$targetUUID} - " . $e->getMessage());
            $admin->sendMessage($this->plugin->getMessage("errors.invalid_uuid_data"));
            return false;
        }
        
        $targetPlayer = $this->plugin->getServer()->getPlayerByUuid($uuidObject);
        
        if ($targetPlayer === null || !$targetPlayer->isOnline()) {
            $admin->sendMessage($this->plugin->getMessage("errors.player_offline"));
            return false;
        }

        if ($this->isPlayerLocked($targetPlayer)) {
            $admin->sendMessage($this->plugin->getMessage("errors.rollback_in_progress"));
            return false;
        }

        $this->lockPlayer($targetPlayer);
        $this->setCooldown($admin);
        
        $admin->sendMessage($this->plugin->getMessage("rollback.processing", ["id" => $globalId]));

        $deathData = $targetRecord["data"];
        $deathCoords = $deathData["coords"];

        $this->debugLog("Starting rollback for player {$targetPlayer->getName()} (ID: {$globalId})");

        $this->clearAllPlayerInventories($targetPlayer);
        $this->debugLog("Cleared {$targetPlayer->getName()}'s inventories to prevent duplication");

        if ($this->plugin->getConfigValue("anti_dupe.clear_ground_items", true)) {
            $this->removeLootFromGround($deathCoords, $deathData);
        }

        if ($this->plugin->getConfigValue("anti_dupe.clear_nearby_players", true)) {
            $this->clearNearbyPlayersInventories($deathCoords, $deathData);
        }

        if ($this->plugin->getConfigValue("anti_dupe.clear_nearby_containers", true)) {
            $this->clearNearbyContainers($deathCoords, $deathData);
        }

        $delay = $this->plugin->getConfigValue("anti_dupe.verification_delay_ticks", 20);
        
        $this->plugin->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function() use ($admin, $targetPlayer, $deathData, $targetUUID, $globalId): void {
                $this->completeRollbackSequence($admin, $targetPlayer, $deathData, $targetUUID, $globalId);
            }), 
            $delay
        );

        return true;
    }

    private function completeRollbackSequence(Player $admin, Player $targetPlayer, array $deathData, string $targetUUID, int $globalId): void {
        if (!$targetPlayer->isOnline()) {
            $admin->sendMessage($this->plugin->getMessage("errors.player_offline"));
            $this->unlockPlayer($targetPlayer);
            return;
        }
        
        try {
            $this->debugLog("Executing restore for {$targetPlayer->getName()}");

            $success = $this->restoreInventory($targetPlayer, $deathData);

            if (!$success) {
                $admin->sendMessage($this->plugin->getMessage("errors.deserialization_failed"));
                $this->unlockPlayer($targetPlayer);
                return;
            }

            $this->plugin->getDataManager()->deleteDeathRecord($targetUUID, $globalId);

            $deathCoords = $deathData["coords"];
            
            $this->plugin->getLogManager()->logRollback(
                $admin->getName(),
                $targetPlayer->getName(),
                $globalId,
                time()
            );

            if ($this->plugin->getConfigValue("performance.async_webhooks", true)) {
                $this->plugin->getScheduler()->scheduleDelayedTask(
                    new ClosureTask(function() use ($admin, $targetPlayer, $globalId, $deathCoords): void {
                        $this->plugin->getLogManager()->sendWebhookNotification(
                            $admin->getName(),
                            $targetPlayer->getName(),
                            $globalId,
                            $deathCoords
                        );
                    }), 
                    1
                );
            } else {
                $this->plugin->getLogManager()->sendWebhookNotification(
                    $admin->getName(),
                    $targetPlayer->getName(),
                    $globalId,
                    $deathCoords
                );
            }
            
            $admin->sendMessage($this->plugin->getMessage("rollback.success", ["id" => $globalId]));
            
            if ($this->plugin->getConfigValue("rollback_behavior.notify_target_player", true)) {
                $targetPlayer->sendMessage($this->plugin->getMessage("rollback.restored_notification", ["admin" => $admin->getName()]));
            }

            $this->debugLog("Rollback completed successfully for {$targetPlayer->getName()} (ID: {$globalId})");

        } catch (\Exception $e) {
            $admin->sendMessage($this->plugin->getMessage("errors.unexpected_error"));
            $this->plugin->getLogger()->error("Rollback sequence failed for ID {$globalId}: " . $e->getMessage());
            $this->debugLog("Rollback failed with exception: " . $e->getMessage());
        } finally {
            $this->unlockPlayer($targetPlayer);
        }
    }

    private function removeLootFromGround(array $coords, array $deathData): void {
        try {
            $worldManager = $this->plugin->getServer()->getWorldManager();
            $world = $worldManager->getWorldByName($coords["world"]);

            if ($world === null) {
                $this->debugLog("World {$coords['world']} not found for ground clearing");
                return;
            }

            $deathPos = new Vector3($coords["x"], $coords["y"], $coords["z"]);
            $radius = $this->plugin->getConfigValue("anti_dupe.ground_clear_radius", 15);

            $bb = new AxisAlignedBB(
                $deathPos->x - $radius,
                $deathPos->y - $radius,
                $deathPos->z - $radius,
                $deathPos->x + $radius,
                $deathPos->y + $radius,
                $deathPos->z + $radius
            );

            $deathItemsSignature = $this->buildItemSignature($deathData);

            $removedItemEntities = 0;
            
            foreach ($world->getEntities() as $entity) {
                if ($entity instanceof ItemEntity) {
                    $entityPos = $entity->getPosition();
                    if ($bb->isVectorInside($entityPos->asVector3())) {
                        $item = $entity->getItem();
                        if ($this->isItemFromDeath($item, $deathItemsSignature)) {
                            $entity->flagForDespawn();
                            $entity->close();
                            $removedItemEntities++;
                            $this->debugLog("Removed ground item: {$item->getName()} x{$item->getCount()}");
                        }
                    }
                }
            }

            $this->debugLog("Anti-Dupe Ground Clearing Complete: {$removedItemEntities} items removed");

        } catch (\Exception $e) {
            $this->plugin->getLogger()->warning("Failed to remove loot: " . $e->getMessage());
            $this->debugLog("Ground clearing exception: " . $e->getMessage());
        }
    }

    private function clearNearbyPlayersInventories(array $coords, array $deathData): void {
        try {
            $worldManager = $this->plugin->getServer()->getWorldManager();
            $world = $worldManager->getWorldByName($coords["world"]);

            if ($world === null) {
                return;
            }

            $deathPos = new Vector3($coords["x"], $coords["y"], $coords["z"]);
            $radius = $this->plugin->getConfigValue("anti_dupe.ground_clear_radius", 15);

            $bb = new AxisAlignedBB(
                $deathPos->x - $radius,
                $deathPos->y - $radius,
                $deathPos->z - $radius,
                $deathPos->x + $radius,
                $deathPos->y + $radius,
                $deathPos->z + $radius
            );

            $deathItemsSignature = $this->buildItemSignature($deathData);
            $clearedItems = 0;

            foreach ($world->getPlayers() as $nearbyPlayer) {
                $playerPos = $nearbyPlayer->getPosition();
                if ($bb->isVectorInside($playerPos->asVector3())) {
                    $removed = $this->removeMatchingItems($nearbyPlayer, $deathItemsSignature);
                    if ($removed > 0) {
                        $clearedItems += $removed;
                        $this->debugLog("Removed {$removed} matching items from {$nearbyPlayer->getName()}");
                    }
                }
            }

            $this->debugLog("Anti-Dupe Player Clearing Complete: {$clearedItems} items removed");

        } catch (\Exception $e) {
            $this->plugin->getLogger()->warning("Failed to clear nearby players: " . $e->getMessage());
        }
    }

    private function clearNearbyContainers(array $coords, array $deathData): void {
        try {
            $worldManager = $this->plugin->getServer()->getWorldManager();
            $world = $worldManager->getWorldByName($coords["world"]);

            if ($world === null) {
                return;
            }

            $deathPos = new Vector3($coords["x"], $coords["y"], $coords["z"]);
            $radius = $this->plugin->getConfigValue("anti_dupe.ground_clear_radius", 15);

            $bb = new AxisAlignedBB(
                $deathPos->x - $radius,
                $deathPos->y - $radius,
                $deathPos->z - $radius,
                $deathPos->x + $radius,
                $deathPos->y + $radius,
                $deathPos->z + $radius
            );

            $deathItemsSignature = $this->buildItemSignature($deathData);
            $clearedFromContainers = 0;

            $minX = (int)floor($bb->minX);
            $maxX = (int)ceil($bb->maxX);
            $minY = (int)floor($bb->minY);
            $maxY = (int)ceil($bb->maxY);
            $minZ = (int)floor($bb->minZ);
            $maxZ = (int)ceil($bb->maxZ);

            for ($x = $minX; $x <= $maxX; $x++) {
                for ($y = $minY; $y <= $maxY; $y++) {
                    for ($z = $minZ; $z <= $maxZ; $z++) {
                        $tile = $world->getTileAt($x, $y, $z);
                        
                        if ($tile !== null && method_exists($tile, 'getInventory')) {
                            $inventory = $tile->getInventory();
                            if ($inventory !== null) {
                                $removed = $this->removeMatchingItemsFromInventory($inventory, $deathItemsSignature);
                                if ($removed > 0) {
                                    $clearedFromContainers += $removed;
                                    $this->debugLog("Removed {$removed} items from container at {$x}, {$y}, {$z}");
                                }
                            }
                        }
                    }
                }
            }

            $this->debugLog("Anti-Dupe Container Clearing Complete: {$clearedFromContainers} items removed");

        } catch (\Exception $e) {
            $this->plugin->getLogger()->warning("Failed to clear nearby containers: " . $e->getMessage());
        }
    }

    private function buildItemSignature(array $deathData): array {
        $signature = [];
        
        if (isset($deathData['inventory'])) {
            foreach ($deathData['inventory'] as $itemData) {
                if (empty($itemData)) continue;
                
                $item = ItemSerializer::deserialize($itemData);
                if ($item !== null && !$item->isNull()) {
                    $key = $this->getItemKey($item);
                    if (!isset($signature[$key])) {
                        $signature[$key] = 0;
                    }
                    $signature[$key] += $item->getCount();
                }
            }
        }
        
        if (isset($deathData['armor'])) {
            foreach ($deathData['armor'] as $itemData) {
                if (empty($itemData)) continue;
                
                $item = ItemSerializer::deserialize($itemData);
                if ($item !== null && !$item->isNull()) {
                    $key = $this->getItemKey($item);
                    if (!isset($signature[$key])) {
                        $signature[$key] = 0;
                    }
                    $signature[$key] += $item->getCount();
                }
            }
        }
        
        return $signature;
    }

    private function getItemKey(Item $item): string {
        try {
            $nbt = $item->nbtSerialize();
            $nbtHash = md5($nbt->toString());
            return $item->getTypeId() . ":" . $item->getStateId() . ":" . $nbtHash;
        } catch (\Exception $e) {
            return $item->getTypeId() . ":" . $item->getStateId() . ":no_nbt";
        }
    }

    private function isItemFromDeath(Item $item, array $signature): bool {
        $key = $this->getItemKey($item);
        return isset($signature[$key]) && $signature[$key] > 0;
    }

    private function removeMatchingItems(Player $player, array $signature): int {
        $removed = 0;
        
        $removed += $this->removeMatchingItemsFromInventory($player->getInventory(), $signature);
        $removed += $this->removeMatchingItemsFromInventory($player->getArmorInventory(), $signature);
        $removed += $this->removeMatchingItemsFromInventory($player->getEnderInventory(), $signature);
        $removed += $this->removeMatchingItemsFromInventory($player->getCursorInventory(), $signature);
        
        return $removed;
    }

    private function removeMatchingItemsFromInventory($inventory, array &$signature): int {
        $removed = 0;
        
        foreach ($inventory->getContents() as $slot => $item) {
            if ($item->isNull()) continue;
            
            $key = $this->getItemKey($item);
            
            if (isset($signature[$key]) && $signature[$key] > 0) {
                $removeCount = min($item->getCount(), $signature[$key]);
                
                if ($removeCount >= $item->getCount()) {
                    $inventory->clear($slot);
                    $removed += $item->getCount();
                    $signature[$key] -= $item->getCount();
                    $this->debugLog("Removed full stack: {$item->getName()} x{$item->getCount()} from slot {$slot}");
                } else {
                    $item->setCount($item->getCount() - $removeCount);
                    $inventory->setItem($slot, $item);
                    $removed += $removeCount;
                    $signature[$key] -= $removeCount;
                    $this->debugLog("Reduced stack: {$item->getName()} by {$removeCount} in slot {$slot}");
                }
            }
        }
        
        return $removed;
    }

    private function clearAllPlayerInventories(Player $player): void {
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getEnderInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
    }

    private function restoreInventory(Player $player, array $deathData): bool {
        try {
            $inventory = $player->getInventory();
            $armorInventory = $player->getArmorInventory();
            
            // CRITICAL: Clear ALL inventories including ender and cursor
            $inventory->clearAll();
            $armorInventory->clearAll();
            $player->getEnderInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            
            $this->debugLog("FORCED CLEAR: All inventories cleared for {$player->getName()}");
            
            $restoredItems = 0;
            $dropLocation = $player->getPosition();

            // Restore main inventory
            if (isset($deathData["inventory"])) {
                foreach ($deathData["inventory"] as $slot => $itemData) {
                    if (empty($itemData)) continue;
                    
                    $item = ItemSerializer::deserialize($itemData);
                    
                    if ($item !== null && !$item->isNull()) {
                        $slotNum = (int)$slot;
                        $inventory->setItem($slotNum, $item);
                        $restoredItems++;
                        $this->debugLog("Restored: {$item->getName()} x{$item->getCount()} to slot {$slotNum}");
                    }
                }
            }

            // Restore armor
            if (isset($deathData["armor"])) {
                $armorData = $deathData["armor"];
                
                if (isset($armorData["helmet"]) && !empty($armorData["helmet"])) {
                    $item = ItemSerializer::deserialize($armorData["helmet"]);
                    if ($item !== null && !$item->isNull()) {
                        $armorInventory->setHelmet($item);
                        $restoredItems++;
                        $this->debugLog("Restored helmet");
                    }
                }
                
                if (isset($armorData["chestplate"]) && !empty($armorData["chestplate"])) {
                    $item = ItemSerializer::deserialize($armorData["chestplate"]);
                    if ($item !== null && !$item->isNull()) {
                        $armorInventory->setChestplate($item);
                        $restoredItems++;
                        $this->debugLog("Restored chestplate");
                    }
                }
                
                if (isset($armorData["leggings"]) && !empty($armorData["leggings"])) {
                    $item = ItemSerializer::deserialize($armorData["leggings"]);
                    if ($item !== null && !$item->isNull()) {
                        $armorInventory->setLeggings($item);
                        $restoredItems++;
                        $this->debugLog("Restored leggings");
                    }
                }
                
                if (isset($armorData["boots"]) && !empty($armorData["boots"])) {
                    $item = ItemSerializer::deserialize($armorData["boots"]);
                    if ($item !== null && !$item->isNull()) {
                        $armorInventory->setBoots($item);
                        $restoredItems++;
                        $this->debugLog("Restored boots");
                    }
                }
            }

            $this->debugLog("RESTORATION COMPLETE: {$restoredItems} items restored for {$player->getName()}");
            return true;
            
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Restore failed for {$player->getName()}: " . $e->getMessage());
            $this->debugLog("Restore exception: " . $e->getMessage());
            return false;
        }
    }

    private function getDeathLocation(array $deathData): Vector3 {
        $coords = $deathData["coords"];
        return new Vector3((float)$coords["x"], (float)$coords["y"], (float)$coords["z"]);
    }

    private function isPlayerLocked(Player $player): bool {
        return isset($this->activeLocks[$player->getUniqueId()->toString()]);
    }

    private function lockPlayer(Player $player): void {
        $this->activeLocks[$player->getUniqueId()->toString()] = time();
        $this->debugLog("Locked player: {$player->getName()}");
    }

    private function unlockPlayer(Player $player): void {
        unset($this->activeLocks[$player->getUniqueId()->toString()]);
        $this->debugLog("Unlocked player: {$player->getName()}");
    }

    private function isOnCooldown(Player $player): bool {
        $cooldownTime = $this->plugin->getConfigValue("rate_limiting.rollback_cooldown", 10);
        
        if (!isset($this->rollbackCooldowns[$player->getUniqueId()->toString()])) {
            return false;
        }

        return (time() - $this->rollbackCooldowns[$player->getUniqueId()->toString()]) < $cooldownTime;
    }

    private function getRemainingCooldown(Player $player): int {
        $cooldownTime = $this->plugin->getConfigValue("rate_limiting.rollback_cooldown", 10);
        $lastUse = $this->rollbackCooldowns[$player->getUniqueId()->toString()] ?? 0;
        $elapsed = time() - $lastUse;
        
        return max(0, $cooldownTime - $elapsed);
    }

    private function setCooldown(Player $player): void {
        $this->rollbackCooldowns[$player->getUniqueId()->toString()] = time();
    }

    private function debugLog(string $message): void {
        if ($this->debugEnabled && $this->plugin->getConfigValue("debug.log_rollback_steps", false)) {
            $this->plugin->getLogger()->debug("[RollbackManager] " . $message);
        }
    }

    public function getActiveLocks(): array {
        return $this->activeLocks;
    }

    public function clearExpiredLocks(): int {
        $cleared = 0;
        $maxLockTime = 30;
        
        foreach ($this->activeLocks as $uuid => $timestamp) {
            if ((time() - $timestamp) > $maxLockTime) {
                unset($this->activeLocks[$uuid]);
                $cleared++;
                $this->debugLog("Cleared expired lock for UUID: {$uuid}");
            }
        }
        
        return $cleared;
    }
}