<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'system_settings')]
class SystemSettingEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $key;

    #[ORM\Column(type: 'jsonb')]
    private mixed $value;

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

    public function setValue(mixed $value): void
    {
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
