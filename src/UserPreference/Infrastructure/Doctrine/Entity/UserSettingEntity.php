<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * Schema mapping for user_settings (Version20261007120000). Reads and writes belong to the
 * DBAL UserSettingRepository, never a managed-entity flush. The owner is a scalar user ID;
 * UserPreferenceForeignKeys declares its foreign key.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'user_settings')]
class UserSettingEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $userId;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private string $key;

    #[ORM\Column(type: 'jsonb')]
    private bool|int|string $value;

    // The project maps timestamptz as datetime_immutable.
    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $userId, string $key, bool|int|string $value)
    {
        $this->userId = $userId;
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): bool|int|string
    {
        return $this->value;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
