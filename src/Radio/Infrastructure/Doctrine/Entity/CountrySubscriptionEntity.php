<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'country_subscriptions')]
#[ORM\UniqueConstraint(name: 'country_subscriptions_user_id_source_id_country_code_key', columns: ['user_id', 'source_id', 'country_code'])]
class CountrySubscriptionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceId;

    #[ORM\Column(type: 'text')]
    private string $countryCode;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        Uuid $userId,
        Uuid $sourceId,
        string $countryCode,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->sourceId = $sourceId;
        $this->countryCode = $countryCode;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getSourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): void
    {
        $this->lastSyncedAt = $lastSyncedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
