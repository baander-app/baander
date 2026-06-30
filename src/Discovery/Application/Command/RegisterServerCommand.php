<?php

declare(strict_types=1);

namespace App\Discovery\Application\Command;

final readonly class RegisterServerCommand
{
    public function __construct(
        private string $serverUrl,
        private string $name,
        private string $version,
        private string $apiKey,
    ) {
    }

    public function getServerUrl(): string { return $this->serverUrl; }
    public function getName(): string { return $this->name; }
    public function getVersion(): string { return $this->version; }
    public function getApiKey(): string { return $this->apiKey; }
}
