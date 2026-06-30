<?php

declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;

/**
 * Plain data model representing a registered Baander server in the cloud relay.
 *
 * Intentionally simple — no aggregates, no events, no state objects.
 * The main Baander backend handles all complex logic.
 */
final class ServerRegistry
{
    private DateTimeImmutable $updatedAt;

    public function __construct(
        public readonly string $publicId,
        public readonly string $url,
        public readonly string $name,
        public string $version,
        public ?DateTimeImmutable $lastHeartbeat = null,
        public readonly ?string $apiKey = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {
        $this->lastHeartbeat ??= new DateTimeImmutable();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getApiKeyHash(): string
    {
        return hash('sha256', $this->apiKey ?? '');
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->lastHeartbeat = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function updateVersion(string $version): void
    {
        $this->version = $version;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Serialize to API response array.
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'url' => $this->url,
            'name' => $this->name,
            'version' => $this->version,
            'lastHeartbeat' => $this->lastHeartbeat?->format(DateTimeImmutable::ATOM),
        ];
    }
}
