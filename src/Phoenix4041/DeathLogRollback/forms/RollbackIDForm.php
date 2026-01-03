<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\forms;

use pocketmine\form\Form;
use pocketmine\player\Player;
use Phoenix4041\DeathLogRollback\Loader;

class RollbackIDForm implements Form {

    private Loader $plugin;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
    }

    public function jsonSerialize(): array {
        return [
            "type" => "custom_form",
            "title" => "§l§8Devolver Inventario",
            "content" => [
                [
                    "type" => "input",
                    "text" => "§7ID Global de la muerte:",
                    "placeholder" => "Ejemplo: 123"
                ]
            ]
        ];
    }

    public function handleResponse(Player $player, $data): void {
        if ($data === null || !isset($data[0]) || trim($data[0]) === "") {
            $player->sendMessage("§c[DeathLog] Debes ingresar una ID válida");
            return;
        }

        $globalId = (int)trim($data[0]);

        if ($globalId <= 0) {
            $player->sendMessage("§c[DeathLog] La ID debe ser un número mayor a 0");
            return;
        }

        $this->plugin->executeRollback($player, $globalId);
    }
}