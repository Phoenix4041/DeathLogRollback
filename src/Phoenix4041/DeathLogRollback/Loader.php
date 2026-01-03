<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use Phoenix4041\DeathLogRollback\manager\DataManager;
use Phoenix4041\DeathLogRollback\listener\PlayerListener;
use Phoenix4041\DeathLogRollback\task\PurgeTask;
use Phoenix4041\DeathLogRollback\forms\MainMenuForm;
use Phoenix4041\DeathLogRollback\forms\SearchPlayerForm;
use Phoenix4041\DeathLogRollback\forms\RollbackIDForm;
use Phoenix4041\DeathLogRollback\forms\DeathListForm;
use Phoenix4041\DeathLogRollback\manager\RollbackManager;
use Phoenix4041\DeathLogRollback\task\WebhookTask;
use Phoenix4041\DeathLogRollback\utils\ItemSerializer;
use Phoenix4041\DeathLogRollback\manager\DeathTracker;
use Phoenix4041\DeathLogRollback\manager\LogManager;
use Phoenix4041\DeathLogRollback\utils\MessageManager;

class Loader extends PluginBase {
    use SingletonTrait;

    private DataManager $dataManager;
    private RollbackManager $rollbackManager;
    private LogManager $logManager;
    private DeathTracker $deathTracker;
    private MessageManager $messageManager;
    private array $config;
    private array $messages;

    protected function onLoad(): void {
        self::setInstance($this);
    }

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->saveResource("messages.yml");
        
        $this->config = $this->getConfig()->getAll();
        
        $messagesConfig = new Config($this->getDataFolder() . "messages.yml", Config::YAML);
        $this->messages = $messagesConfig->getAll();

        @mkdir($this->getDataFolder() . "data");

        $this->dataManager = new DataManager($this);
        $this->deathTracker = new DeathTracker($this);
        $this->rollbackManager = new RollbackManager($this);
        $this->logManager = new LogManager($this);

        $this->getServer()->getPluginManager()->registerEvents(new PlayerListener($this), $this);

        $purgeInterval = $this->parsePurgeInterval($this->config["purge_interval"] ?? "7d");
        $this->getScheduler()->scheduleRepeatingTask(new PurgeTask($this), $purgeInterval * 20);

    }

    protected function onDisable(): void {
        if (isset($this->dataManager)) {
            $this->dataManager->saveAll();
        }
        if (isset($this->logManager)) {
            $this->logManager->saveLog();
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() !== "rollback") {
            return false;
        }

        if (!($sender instanceof Player)) {
            $sender->sendMessage("§c[DeathLog] Este comando solo puede ser usado por jugadores");
            return true;
        }

        if (!$sender->hasPermission("deathlog.rollback")) {
            $sender->sendMessage($this->getMessage("permission_denied"));
            return true;
        }

        $this->sendMainMenu($sender);
        return true;
    }

    public function sendMainMenu(Player $player): void {
        $form = new MainMenuForm($this);
        $player->sendForm($form);
    }

    public function sendSearchPlayerForm(Player $player): void {
        $form = new SearchPlayerForm($this);
        $player->sendForm($form);
    }

    public function sendDeathListForm(Player $player, string $targetName): void {
        $form = new DeathListForm($this, $targetName);
        $player->sendForm($form);
    }

    public function sendRollbackIDForm(Player $player): void {
        $form = new RollbackIDForm($this);
        $player->sendForm($form);
    }

    /**
     * Execute inventory rollback
     */
    public function executeRollback(Player $admin, int $globalId): void {
        $this->rollbackManager->executeRollback($admin, $globalId);
    }

    public function getDataManager(): DataManager {
        return $this->dataManager;
    }

    public function getRollbackManager(): RollbackManager {
        return $this->rollbackManager;
    }

    public function getLogManager(): LogManager {
        return $this->logManager;
    }

    public function getDeathTracker(): DeathTracker {
        return $this->deathTracker;
    }

    public function getConfigValue(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    public function getMessage(string $key, array $vars = []): string {
        $keys = explode(".", $key);
        $message = $this->messages;
        
        foreach ($keys as $k) {
            if (isset($message[$k])) {
                $message = $message[$k];
            } else {
                return "Message not found: $key";
            }
        }
        
        if (!is_string($message)) {
            return "Message not found: $key";
        }
        
        $prefix = $this->messages["prefix"] ?? "";
        $vars["prefix"] = $prefix;
        
        foreach ($vars as $var => $value) {
            $message = str_replace("{" . $var . "}", (string)$value, $message);
        }
        
        return $message;
    }

    private function parsePurgeInterval(string $interval): int {
        $unit = substr($interval, -1);
        $value = (int)substr($interval, 0, -1);

        return match($unit) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            'w' => $value * 604800,
            default => 604800
        };
    }

    public function formatTimestamp(int $timestamp): string {
        return date($this->config["time_format"] ?? "d/m/Y H:i:s", $timestamp);
    }
}