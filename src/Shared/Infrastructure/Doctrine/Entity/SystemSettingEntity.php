<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Schema mapping for system_settings. Reads and writes belong to the DBAL
 * SystemSettingRepository, which bypasses the identity map (never a managed-entity flush).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'system_settings')]
class SystemSettingEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $key;

    #[ORM\Column(type: 'jsonb')]
    private mixed $value;

    // timestamptz since Version20261007110000; the project maps timestamptz as datetime_immutable.
    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'now()'])]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $key, mixed $value)
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
