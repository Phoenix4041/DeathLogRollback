<?php

declare(strict_types=1);

namespace Phoenix4041\DeathLogRollback\task;

use pocketmine\scheduler\AsyncTask;

class WebhookTask extends AsyncTask {

    private string $webhookUrl;
    private string $jsonData;

    public function __construct(string $webhookUrl, string $jsonData) {
        $this->webhookUrl = $webhookUrl;
        $this->jsonData = $jsonData;
    }

    public function onRun(): void {
        $ch = curl_init($this->webhookUrl);
        
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Content-Length: " . strlen($this->jsonData)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        
        $this->setResult([
            "success" => $httpCode >= 200 && $httpCode < 300,
            "http_code" => $httpCode
        ]);
    }

    public function onCompletion(): void {
    }
}