<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Model;

use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;

/**
 * Aggregate root for a server pairing session.
 *
 * Lifecycle: pending → completed | expired.
 * Modeled after OAuth DeviceCode (RFC 8628) approve/deny/consume pattern.
 */
final class PairingSession
{
    private function __construct(
        private PairingSessionState $state,
    ) {
    }

    public static function create(
        Uuid $serverId,
        PublicId $serverPublicId,
        string $serverUrl,
        string $serverName,
        AuthenticationMethod $method,
        ?DateInterval $ttl = null,
    ): self {
        $ttl ??= new DateInterval('PT5M');
        $now = new DateTimeImmutable();

        return new self(new PairingSessionState(
            id: new Uuid(),
            publicId: new PublicId(),
            serverId: $serverId,
            serverPublicId: $serverPublicId,
            serverUrl: $serverUrl,
            serverName: $serverName,
            pairingCode: PairingCode::generate(),
            method: $method,
            expiresAt: $now->add($ttl),
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public static function reconstitute(PairingSessionState $state): self
    {
        return new self($state);
    }

    public function complete(): void
    {
        if ($this->state->completedAt !== null) {
            return;
        }
        if ($this->state->expiredAt !== null || $this->isExpired()) {
            throw new RuntimeException('Cannot complete an expired pairing session.');
        }
        $this->state->completedAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function expire(): void
    {
        if ($this->state->completedAt !== null) {
            return;
        }
        if ($this->state->expiredAt !== null) {
            return;
        }
        $this->state->expiredAt = new DateTimeImmutable();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return $this->state->completedAt === null && $this->state->expiredAt === null && !$this->isExpired();
    }

    public function isCompleted(): bool
    {
        return $this->state->completedAt !== null;
    }

    public function isExpired(): bool
    {
        return $this->state->expiresAt !== null && $this->state->expiresAt < new DateTimeImmutable();
    }

    public function getQrPayload(): string
    {
        return sprintf(
            'baander://pair?server=%s&code=%s',
            $this->state->serverPublicId->toString(),
            $this->state->pairingCode->toString(),
        );
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getServerId(): Uuid { return $this->state->serverId; }
    public function getServerPublicId(): PublicId { return $this->state->serverPublicId; }
    public function getServerUrl(): string { return $this->state->serverUrl; }
    public function getServerName(): string { return $this->state->serverName; }
    public function getPairingCode(): PairingCode { return $this->state->pairingCode; }
    public function getMethod(): AuthenticationMethod { return $this->state->method; }
    public function getExpiresAt(): ?DateTimeImmutable { return $this->state->expiresAt; }
    public function getCompletedAt(): ?DateTimeImmutable { return $this->state->completedAt; }
    public function getExpiredAt(): ?DateTimeImmutable { return $this->state->expiredAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->state->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->state->updatedAt; }
    public function getState(): PairingSessionState { return $this->state; }
}
