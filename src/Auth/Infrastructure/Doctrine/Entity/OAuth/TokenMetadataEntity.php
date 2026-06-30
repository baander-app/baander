<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Entity\OAuth;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'oauth_token_metadata')]
#[ORM\UniqueConstraint(name: 'oauth_token_metadata_token_id_unique', columns: ['token_id'])]
class TokenMetadataEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: AccessTokenEntity::class)]
    #[ORM\JoinColumn(name: 'token_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessTokenEntity $token;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $deviceOperatingSystem = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $deviceName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $clientFingerprint = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $ipHistory = [];

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $ipChangeCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastGeoNotificationAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $broadcastToken = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        AccessTokenEntity $token,
        ?string $userAgent = null,
        ?string $deviceOperatingSystem = null,
        ?string $deviceName = null,
        ?string $clientFingerprint = null,
        ?string $sessionId = null,
        ?string $ipAddress = null,
        ?string $countryCode = null,
        ?string $city = null,
    ) {
        $this->id = new Uuid();
        $this->token = $token;
        $this->userAgent = $userAgent;
        $this->deviceOperatingSystem = $deviceOperatingSystem;
        $this->deviceName = $deviceName;
        $this->clientFingerprint = $clientFingerprint;
        $this->sessionId = $sessionId;
        $this->ipAddress = $ipAddress;
        $this->countryCode = $countryCode;
        $this->city = $city;
        if ($ipAddress !== null) {
            $this->ipHistory[] = ['ip' => $ipAddress, 'seen_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
        }
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getToken(): AccessTokenEntity
    {
        return $this->token;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): void
    {
        $this->userAgent = $userAgent;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function setIpAddress(?string $ipAddress): void
    {
        if ($ipAddress !== null && $ipAddress !== $this->ipAddress) {
            $this->ipHistory[] = ['ip' => $ipAddress, 'seen_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
            $this->ipChangeCount++;
        }
        $this->ipAddress = $ipAddress;
        $this->updatedAt = new \DateTimeImmutable();
    }

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

    public function setCountryCode(?string $countryCode): void
    {
        $this->countryCode = $countryCode;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLastGeoNotificationAt(): ?\DateTimeImmutable
    {
        return $this->lastGeoNotificationAt;
    }

    public function markGeoNotified(): void
    {
        $this->lastGeoNotificationAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBroadcastToken(): ?string
    {
        return $this->broadcastToken;
    }

    public function setBroadcastToken(?string $broadcastToken): void
    {
        $this->broadcastToken = $broadcastToken;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
