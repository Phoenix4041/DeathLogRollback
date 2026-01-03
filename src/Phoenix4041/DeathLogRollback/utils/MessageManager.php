<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\utils;

use pocketmine\utils\Config;
use Phoenix4041\DeathLogRollback\Loader;

class MessageManager {

    private Loader $plugin;
    private Config $messages;
    private string $prefix;

    public function __construct(Loader $plugin) {
        $this->plugin = $plugin;
        
        $plugin->saveResource("messages.yml");
        $this->messages = new Config($plugin->getDataFolder() . "messages.yml", Config::YAML);
        $this->prefix = $this->messages->get("prefix", "§8[§eDeathLog§8]");
    }

    public function getMessage(string $path, array $vars = []): string {
        $message = $this->getNestedValue($path);
        
        if ($message === null) {
            return "§cMessage not found: {$path}";
        }

        $vars["prefix"] = $this->prefix;
        
        foreach ($vars as $key => $value) {
            $message = str_replace("{" . $key . "}", (string)$value, $message);
        }
        
        return $message;
    }

    private function getNestedValue(string $path): ?string {
        $keys = explode(".", $path);
        $value = $this->messages->getAll();
        
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        
        return is_string($value) ? $value : null;
    }

    public function reload(): void {
        $this->messages->reload();
        $this->prefix = $this->messages->get("prefix", "§8[§eDeathLog§8]");
    }

    public function getPrefix(): string {
        return $this->prefix;
    }
}