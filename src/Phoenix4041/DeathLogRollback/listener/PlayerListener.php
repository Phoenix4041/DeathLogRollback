<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use Phoenix4041\DeathLogRollback\Loader;
use Phoenix4041\DeathLogRollback\utils\ItemSerializer;

class PlayerListener implements Listener {

    private Loader $plugin;
    private array $deathCooldowns = [];
    private bool $spamProtectionEnabled;
    private int $cooldownTime;
    private bool $debugEnabled;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
        $this->spamProtectionEnabled = $plugin->getConfigValue("rate_limiting.enable_death_spam_protection", true);
        $this->cooldownTime = $plugin->getConfigValue("rate_limiting.death_record_cooldown", 5);
        $this->debugEnabled = $plugin->getConfigValue("debug.enabled", false);
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $playerUUID = $player->getUniqueId()->toString();

        if ($this->spamProtectionEnabled && $this->isOnCooldown($playerUUID)) {
            $this->debugLog("Death recording blocked for {$playerName} (cooldown active)");
            $player->sendMessage($this->plugin->getMessage("death_spam_blocked"));
            return;
        }

        if ($player->isCreative()) {
            $this->debugLog("Death recording skipped for {$playerName} (creative mode)");
            return;
        }

        $inventory = [];
        $armor = [];

        if ($this->plugin->getConfigValue("save_inventory", true)) {
            $inventory = ItemSerializer::serializeInventory($player->getInventory());
            $this->debugLog("Serialized {$playerName}'s inventory: " . count($inventory) . " items");
        }

        if ($this->plugin->getConfigValue("save_armor", true)) {
            $armor = ItemSerializer::serializeArmor($player->getArmorInventory());
            $this->debugLog("Serialized {$playerName}'s armor");
        }

        $position = $player->getPosition();
        $deathData = [
            "inventory" => $inventory,
            "armor" => $armor,
            "coords" => [
                "x" => (int)$position->getX(),
                "y" => (int)$position->getY(),
                "z" => (int)$position->getZ(),
                "world" => $position->getWorld()->getFolderName()
            ],
            "cause" => $event->getDeathMessage()->getText(),
            "timestamp" => time()
        ];

        try {
            $deathId = $this->plugin->getDataManager()->addDeathRecord(
                $playerName,
                $playerUUID,
                $deathData
            );

            if ($this->spamProtectionEnabled) {
                $this->setCooldown($playerUUID);
            }

            if ($this->plugin->getConfigValue("logging.log_local_enabled", true)) {
                $this->plugin->getLogger()->info(
                    "Death recorded: {$playerName} (ID: {$deathId}) at " .
                    "{$deathData['coords']['x']}, {$deathData['coords']['y']}, {$deathData['coords']['z']}"
                );
            }

            $player->sendMessage($this->plugin->getMessage("death.recorded", ["id" => $deathId]));
            $player->sendMessage($this->plugin->getMessage("death.coordinates", [
                "x" => $deathData['coords']['x'],
                "y" => $deathData['coords']['y'],
                "z" => $deathData['coords']['z']
            ]));

            $this->debugLog("Successfully recorded death for {$playerName} with ID {$deathId}");
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Failed to record death for {$playerName}: " . $e->getMessage());
            $this->debugLog("Death recording exception: " . $e->getMessage());
        }
    }

    private function isOnCooldown(string $uuid): bool {
        if (!isset($this->deathCooldowns[$uuid])) {
            return false;
        }

        return (time() - $this->deathCooldowns[$uuid]) < $this->cooldownTime;
    }

    private function setCooldown(string $uuid): void {
        $this->deathCooldowns[$uuid] = time();
    }

    public function cleanupExpiredCooldowns(): int {
        $cleaned = 0;
        $now = time();
        
        foreach ($this->deathCooldowns as $uuid => $timestamp) {
            if (($now - $timestamp) > ($this->cooldownTime + 60)) {
                unset($this->deathCooldowns[$uuid]);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            $this->debugLog("Cleaned up {$cleaned} expired death cooldowns");
        }
        
        return $cleaned;
    }

    private function debugLog(string $message): void {
        if ($this->debugEnabled) {
            $this->plugin->getLogger()->debug("[PlayerListener] " . $message);
        }
    }
}