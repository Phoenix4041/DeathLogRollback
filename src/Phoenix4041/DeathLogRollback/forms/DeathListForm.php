<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\forms;

use pocketmine\form\Form;
use pocketmine\player\Player;
use Phoenix4041\DeathLogRollback\Loader;

class DeathListForm implements Form {

    private Loader $plugin;
    private string $targetName;
    private ?array $deaths = null;

    public function __construct(Loader $plugin, string $targetName) {
        $this->plugin = $plugin;
        $this->targetName = $targetName;
    }

    public function jsonSerialize(): array {
        $targetPlayer = $this->plugin->getServer()->getPlayerByPrefix($this->targetName);
        
        if ($targetPlayer === null) {
            return [
                "type" => "form",
                "title" => "§l§cError",
                "content" => "§cNo se encontró al jugador: {$this->targetName}",
                "buttons" => [
                    ["text" => "§cCerrar"]
                ]
            ];
        }

        $uuid = $targetPlayer->getUniqueId()->toString();
        $this->deaths = $this->plugin->getDataManager()->getPlayerDeaths($uuid);

        if ($this->deaths === null) {
            return [
                "type" => "form",
                "title" => "§l§8Historial de Muertes",
                "content" => "§e{$this->targetName} no tiene registros de muerte",
                "buttons" => [
                    ["text" => "§cCerrar"]
                ]
            ];
        }

        $buttons = [];
        foreach ($this->deaths as $record) {
            $date = $this->plugin->formatTimestamp($record["timestamp"]);
            $buttons[] = [
                "text" => "§l§eID: {$record["id"]}\n§r§7{$date}",
                "image" => [
                    "type" => "path",
                    "data" => "textures/items/paper"
                ]
            ];
        }

        return [
            "type" => "form",
            "title" => "§l§8Historial: {$this->targetName}",
            "content" => "§7Total de registros: §e" . count($this->deaths),
            "buttons" => $buttons
        ];
    }

    public function handleResponse(Player $player, $data): void {
        if ($data === null || $this->deaths === null) {
            return;
        }

        if (!isset($this->deaths[$data])) {
            return;
        }

        $record = $this->deaths[$data];
        $this->sendDeathDetails($player, $record);
    }

    private function sendDeathDetails(Player $player, array $record): void {
        $coords = $record["data"]["coords"];
        $date = $this->plugin->formatTimestamp($record["timestamp"]);
        
        $details = "§l§eID Global: §r§f{$record["id"]}\n\n";
        $details .= "§l§eFecha: §r§7{$date}\n\n";
        $details .= "§l§eCoordenadas:\n";
        $details .= "§7X: §f{$coords["x"]} §7Y: §f{$coords["y"]} §7Z: §f{$coords["z"]}\n";
        $details .= "§7Mundo: §f{$coords["world"]}\n\n";
        $details .= "§l§eCausa: §r§7{$record["data"]["cause"]}";

        $form = [
            "type" => "form",
            "title" => "§l§8Detalles de Muerte",
            "content" => $details,
            "buttons" => [
                ["text" => "§aVolver al Historial"],
                ["text" => "§cCerrar"]
            ]
        ];

        $player->sendForm(new class($this->plugin, $this->targetName, $form) implements Form {
            private Loader $plugin;
            private string $targetName;
            private array $formData;

            public function __construct(Loader $plugin, string $targetName, array $formData) {
                $this->plugin = $plugin;
                $this->targetName = $targetName;
                $this->formData = $formData;
            }

            public function jsonSerialize(): array {
                return $this->formData;
            }

            public function handleResponse(Player $player, $data): void {
                if ($data === 0) {
                    $this->plugin->sendDeathListForm($player, $this->targetName);
                }
            }
        });
    }
}