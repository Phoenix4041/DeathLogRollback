<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\forms;

use pocketmine\form\Form;
use pocketmine\player\Player;
use Phoenix4041\DeathLogRollback\Loader;

class MainMenuForm implements Form {

    private Loader $plugin;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
    }

    public function jsonSerialize(): array {
        return [
            "type" => "form",
            "title" => $this->plugin->getMessage("forms.main_menu.title"),
            "content" => $this->plugin->getMessage("forms.main_menu.content"),
            "buttons" => [
                [
                    "text" => $this->plugin->getMessage("forms.main_menu.button_view"),
                    "image" => [
                        "type" => "path",
                        "data" => "textures/items/book_written"
                    ]
                ],
                [
                    "text" => $this->plugin->getMessage("forms.main_menu.button_rollback"),
                    "image" => [
                        "type" => "path",
                        "data" => "textures/items/bucket_milk"
                    ]
                ]
            ]
        ];
    }

    public function handleResponse(Player $player, $data): void {
        if ($data === null) {
            return;
        }

        switch ($data) {
            case 0:
                $this->plugin->sendSearchPlayerForm($player);
                break;
            case 1:
                $this->plugin->sendRollbackIDForm($player);
                break;
        }
    }
}