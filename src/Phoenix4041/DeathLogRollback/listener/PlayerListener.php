<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\ItemSpawnEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use Phoenix4041\DeathLogRollback\Loader;
use Phoenix4041\DeathLogRollback\utils\ItemSerializer;
use pocketmine\player\Player;

class PlayerListener implements Listener {

    private Loader $plugin;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $playerUUID = $player->getUniqueId()->toString();

        $cause = $player->getLastDamageCause();
        $killerUuid = null;
        $killerName = null;

        // Detecta al asesino si fue PvP
        if ($cause instanceof \pocketmine\event\entity\EntityDamageByEntityEvent) {
            $damager = $cause->getDamager();
            if ($damager instanceof Player) {
                $killerUuid = $damager->getUniqueId()->toString();
                $killerName = $damager->getName();
            }
        }

        $drops = $event->getDrops();
        
        if (count($drops) === 0 && !$event->getKeepInventory()) {
            $forcedDrops = [];
            
            foreach ($player->getInventory()->getContents() as $item) {
                if (!$item->isNull()) {
                    $forcedDrops[] = clone $item;
                }
            }
            
            foreach ($player->getArmorInventory()->getContents() as $item) {
                if (!$item->isNull()) {
                    $forcedDrops[] = clone $item;
                }
            }
            
            if (!empty($forcedDrops)) {
                $event->setDrops($forcedDrops);
            }
        }

        $inventory = [];
        $armor = [];

        if ($this->plugin->getConfigValue("save_inventory", true)) {
            $inventory = ItemSerializer::serializeInventory($player->getInventory());
        }

        if ($this->plugin->getConfigValue("save_armor", true)) {
            $armor = ItemSerializer::serializeArmor($player->getArmorInventory());
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
            "killer" => $killerName,
            "timestamp" => time(),
            "death_time" => microtime(true)
        ];

        $deathId = $this->plugin->getDataManager()->addDeathRecord(
            $playerName,
            $playerUUID,
            $deathData
        );

        // CLAVE: Trackea la muerte y marca al asesino como sospechoso
        $this->plugin->getDeathTracker()->trackDeath($playerUUID, $deathId, $deathData, $killerUuid);

        if ($this->plugin->getConfigValue("logging.log_local_enabled", true)) {
            $killerInfo = $killerName ? " by {$killerName}" : "";
            $this->plugin->getLogger()->info(
                "Death recorded: {$playerName} (ID: {$deathId}){$killerInfo} at " .
                "{$deathData['coords']['x']}, {$deathData['coords']['y']}, {$deathData['coords']['z']}"
            );
        }

        $player->sendMessage($this->plugin->getMessage("death.recorded", ["id" => $deathId]));
    }

    public function onItemSpawn(ItemSpawnEvent $event): void {
        $item = $event->getEntity();
        $position = $item->getPosition();
        
        // DEBUG: Log cuando un item aparece
        $this->plugin->getLogger()->info("§b[DEBUG-SPAWN] Item spawned: " . 
            $item->getItem()->getName() . " x" . $item->getItem()->getCount() . 
            " at (" . round($position->x, 1) . ", " . round($position->y, 1) . ", " . round($position->z, 1) . ")");
        
        $this->plugin->getDeathTracker()->registerDroppedItem(
            $item,
            $position,
            microtime(true)
        );
    }

    public function onItemPickup(EntityItemPickupEvent $event): void {
        $entity = $event->getEntity();
        
        // DEBUG: Log de TODO pickup (incluso si no es jugador)
        $this->plugin->getLogger()->info("§d[DEBUG-PICKUP] EntityItemPickupEvent triggered!");
        
        if (!($entity instanceof Player)) {
            $this->plugin->getLogger()->info("§d[DEBUG-PICKUP] Entity is not a player, ignoring");
            return;
        }

        $player = $entity;
        $itemEntity = $event->getOrigin(); // La ItemEntity que se recogió
        
        // DEBUG: Verificar que getOrigin() funciona
        if ($itemEntity === null) {
            $this->plugin->getLogger()->error("§c[DEBUG-PICKUP] ERROR: getOrigin() returned null!");
            return;
        }
        
        $pickedItem = $itemEntity->getItem(); // El Item dentro de la entity
        
        // DEBUG: Info detallada del item recogido
        $this->plugin->getLogger()->info("§d[DEBUG-PICKUP] Player " . $player->getName() . 
            " picked up: " . $pickedItem->getName() . " x" . $pickedItem->getCount() . 
            " (TypeID: " . $pickedItem->getTypeId() . ")");
        
        $playerUuid = $player->getUniqueId()->toString();

        // Pasamos el Item y el tiempo al tracker
        $this->plugin->getDeathTracker()->trackItemPickup(
            $playerUuid, 
            $pickedItem, 
            microtime(true)
        );
        
        $this->plugin->getLogger()->info("§d[DEBUG-PICKUP] trackItemPickup() called successfully");
    }
}