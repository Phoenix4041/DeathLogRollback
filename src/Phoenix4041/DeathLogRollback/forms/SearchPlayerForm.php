<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\forms;

use pocketmine\form\Form;
use pocketmine\player\Player;
use Phoenix4041\DeathLogRollback\Loader;

class SearchPlayerForm implements Form {

    private Loader $plugin;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
    }

    public function jsonSerialize(): array {
        return [
            "type" => "custom_form",
            "title" => "§l§8Buscar Historial",
            "content" => [
                [
                    "type" => "input",
                    "text" => "§7Nombre del jugador:",
                    "placeholder" => "Ejemplo: Steve"
                ]
            ]
        ];
    }

    public function handleResponse(Player $player, $data): void {
        if ($data === null || !isset($data[0]) || trim($data[0]) === "") {
            $player->sendMessage("§c[DeathLog] Debes ingresar un nombre de jugador");
            return;
        }

        $targetName = trim($data[0]);
        $this->plugin->sendDeathListForm($player, $targetName);
    }
}