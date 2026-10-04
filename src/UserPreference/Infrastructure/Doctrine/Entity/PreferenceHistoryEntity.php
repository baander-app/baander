<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Entity;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'preference_history')]
#[ORM\Index(columns: ['user_id', 'preference_type', 'version'], name: 'idx_pref_history_user_type_version')]
class PreferenceHistoryEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\Column(type: 'text')]
    private string $preferenceType;

    #[ORM\Column(type: 'integer')]
    private int $version;

    /** @var array<array-key, mixed> */
    #[ORM\Column(type: 'jsonb')]
    private array $payload = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Uuid $id)
    {
        $this->id = $id;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->user->getId();
    }

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function setUser(UserEntity $user): void
    {
        $this->user = $user;
    }

    public function getPreferenceType(): string
    {
        return $this->preferenceType;
    }

    public function setPreferenceType(string $preferenceType): void
    {
        $this->preferenceType = $preferenceType;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /** @return array<array-key, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<array-key, mixed> $payload */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
