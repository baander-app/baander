<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Immutable value object representing OAuth token metadata.
 *
 * Captures the environment in which a token was issued and tracks
 * IP address changes across the lifetime of the token for
 * anomaly detection and security monitoring.
 */
final class TokenMetadata
{
    /** @var array<int, array{ip: string, seen_at: string}> */
    private array $ipHistory;

    private function __construct(
        private readonly Uuid $id,
        private readonly Uuid $tokenId,
        private readonly ?string $userAgent,
        private readonly ?string $deviceOperatingSystem,
        private readonly ?string $deviceName,
        private readonly ?string $clientFingerprint,
        private readonly ?string $sessionId,
        private ?string $ipAddress,
        private array $ipHistoryParam,
        private int $ipChangeCount,
        private readonly ?string $countryCode,
        private readonly ?string $city,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->ipHistory = $ipHistoryParam;
    }

    /**
     * Create new token metadata when a token is issued.
     */
    public static function create(
        Uuid $tokenId,
        ?string $userAgent = null,
        ?string $deviceOperatingSystem = null,
        ?string $deviceName = null,
        ?string $clientFingerprint = null,
        ?string $sessionId = null,
        ?string $ipAddress = null,
        ?string $countryCode = null,
        ?string $city = null,
    ): self {
        $now = new DateTimeImmutable();
        $ipHistory = [];

        if ($ipAddress !== null) {
            $ipHistory[] = ['ip' => $ipAddress, 'seen_at' => $now->format(DateTimeInterface::ATOM)];
        }

        return new self(
            Uuid::v7(),
            $tokenId,
            $userAgent,
            $deviceOperatingSystem,
            $deviceName,
            $clientFingerprint,
            $sessionId,
            $ipAddress,
            $ipHistory,
            0,
            $countryCode,
            $city,
            $now,
            $now,
        );
    }

    /**
     * Reconstitute token metadata from persistence.
     *
     * @param array<int, array{ip: string, seen_at: string}> $ipHistory
     */
    public static function reconstitute(
        Uuid $id,
        Uuid $tokenId,
        ?string $userAgent,
        ?string $deviceOperatingSystem,
        ?string $deviceName,
        ?string $clientFingerprint,
        ?string $sessionId,
        ?string $ipAddress,
        array $ipHistory,
        int $ipChangeCount,
        ?string $countryCode,
        ?string $city,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $tokenId,
            $userAgent,
            $deviceOperatingSystem,
            $deviceName,
            $clientFingerprint,
            $sessionId,
            $ipAddress,
            $ipHistory,
            $ipChangeCount,
            $countryCode,
            $city,
            $createdAt,
            $updatedAt,
        );
    }

    /**
     * Record an IP address change.
     *
     * Appends the new IP to the history log and increments the change counter.
     * If the new IP matches the current IP, this is a no-op.
     */
    public function recordIpChange(string $newIp): void
    {
        if ($newIp === $this->ipAddress) {
            return;
        }

        $this->ipHistory[] = ['ip' => $newIp, 'seen_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)];
        $this->ipChangeCount++;
        $this->ipAddress = $newIp;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTokenId(): Uuid
    {
        return $this->tokenId;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getDeviceOperatingSystem(): ?string
    {
        return $this->deviceOperatingSystem;
    }

    public function getDeviceName(): ?string
    {
        return $this->deviceName;
    }

    public function getClientFingerprint(): ?string
    {
        return $this->clientFingerprint;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /**
     * @return array<int, array{ip: string, seen_at: string}>
     */
    public function getIpHistory(): array
    {
        return $this->ipHistory;
    }

    public function getIpChangeCount(): int
    {
        return $this->ipChangeCount;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
