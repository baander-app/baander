<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'radio_sources')]
class RadioSourceEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $type;

    #[ORM\Column(type: 'text')]
    private string $syncUrl;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $syncConfig = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $syncSchedule = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        string $name,
        string $type,
        string $syncUrl,
        array $syncConfig = [],
        ?string $syncSchedule = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->type = $type;
        $this->syncUrl = $syncUrl;
        $this->syncConfig = $syncConfig;
        $this->syncSchedule = $syncSchedule;
        $this->isActive = true;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSyncUrl(): string
    {
        return $this->syncUrl;
    }

    public function setSyncUrl(string $syncUrl): void
    {
        $this->syncUrl = $syncUrl;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSyncConfig(): array
    {
        return $this->syncConfig;
    }

    public function setSyncConfig(array $syncConfig): void
    {
        $this->syncConfig = $syncConfig;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSyncSchedule(): ?string
    {
        return $this->syncSchedule;
    }

    public function setSyncSchedule(?string $syncSchedule): void
    {
        $this->syncSchedule = $syncSchedule;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): void
    {
        $this->isActive = $isActive;
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

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
