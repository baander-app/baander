<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Model;

use App\Discovery\Domain\ValueObject\ServerStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Aggregate root representing a registered self-hosted Baander server.
 *
 * Tracks server health via heartbeats and manages registration lifecycle.
 */
final class ServerInstance
{
    private function __construct(
        private ServerInstanceState $state,
    ) {
    }

    public static function create(
        string $serverUrl,
        string $name,
        string $version,
        string $apiKey,
    ): self {
        $now = new DateTimeImmutable();

        return new self(new ServerInstanceState(
            id: new Uuid(),
            publicId: new PublicId(),
            serverUrl: $serverUrl,
            name: $name,
            apiKey: $apiKey,
            createdAt: $now,
            version: $version,
            status: ServerStatus::Online,
            lastHeartbeatAt: $now,
            updatedAt: $now,
        ));
    }

    public static function reconstitute(ServerInstanceState $state): self
    {
        return new self($state);
    }

    public function updateHeartbeat(): void
    {
        $this->state->lastHeartbeatAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateStatus(ServerStatus $status): void
    {
        $this->state->status = $status;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateVersion(string $version): void
    {
        $this->state->version = $version;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function isHealthy(int $thresholdSeconds = 300): bool
    {
        if ($this->state->lastHeartbeatAt === null) {
            return false;
        }

        $threshold = (new DateTimeImmutable())->modify("-{$thresholdSeconds} seconds");

        return $this->state->lastHeartbeatAt >= $threshold
            && $this->state->status === ServerStatus::Online;
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getServerUrl(): string { return $this->state->serverUrl; }
    public function getName(): string { return $this->state->name; }
    public function getVersion(): string { return $this->state->version; }
    public function getApiKey(): string { return $this->state->apiKey; }
    public function getStatus(): ServerStatus { return $this->state->status; }
    public function getLastHeartbeatAt(): ?DateTimeImmutable { return $this->state->lastHeartbeatAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->state->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->state->updatedAt; }
    public function getState(): ServerInstanceState { return $this->state; }
}
