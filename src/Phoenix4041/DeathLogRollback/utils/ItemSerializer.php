<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\utils;

use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\data\SavedDataLoadingException;

class ItemSerializer {

    public static function serialize(Item $item): string {
        if ($item->isNull()) {
            return "";
        }

        try {
            $nbt = $item->nbtSerialize();
            $serializer = new BigEndianNbtSerializer();
            return base64_encode($serializer->write(new TreeRoot($nbt)));
        } catch (\Exception $e) {
            return "";
        }
    }

    public static function deserialize(string|array $data): ?Item {
        if (is_array($data)) {
            error_log("[DeathLogRollback] Legacy array format detected and skipped.");
            return null;
        }

        if ($data === "") {
            return null;
        }

        try {
            $serializer = new BigEndianNbtSerializer();
            $nbtData = base64_decode($data);
            
            if ($nbtData === false) {
                return null;
            }

            $tag = $serializer->read($nbtData)->mustGetCompoundTag();
            return Item::nbtDeserialize($tag);
        } catch (SavedDataLoadingException | \Exception $e) {
            error_log("[DeathLogRollback] Item deserialization failed: " . $e->getMessage());
            return null;
        }
    }

    public static function serializeArmor(\pocketmine\inventory\ArmorInventory $armorInv): array {
        return [
            "helmet" => self::serialize($armorInv->getHelmet()),
            "chestplate" => self::serialize($armorInv->getChestplate()),
            "leggings" => self::serialize($armorInv->getLeggings()),
            "boots" => self::serialize($armorInv->getBoots())
        ];
    }

    public static function serializeInventory(\pocketmine\inventory\Inventory $inventory): array {
        $serialized = [];
        
        foreach ($inventory->getContents() as $slot => $item) {
            $serialized[(string)$slot] = self::serialize($item);
        }
        
        return $serialized;
    }
}