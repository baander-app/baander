<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'libraries')]
#[ORM\UniqueConstraint(name: 'libraries_slug_unique', columns: ['slug'])]
class LibraryEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $slug;

    #[ORM\Column(type: 'text')]
    private string $path;

    #[ORM\Column(type: 'text')]
    private string $type;

    #[ORM\Column(type: 'text', options: ['default' => 'local'])]
    private string $filesystemType;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastScan = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $scanStatus = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        string $slug,
        string $path,
        string $type,
        string $filesystemType,
        int $sortOrder = 0,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->name = $name;
        $this->slug = $slug;
        $this->path = $path;
        $this->type = $type;
        $this->filesystemType = $filesystemType;
        $this->sortOrder = $sortOrder;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
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

    public function getFilesystemType(): string
    {
        return $this->filesystemType;
    }

    public function setFilesystemType(string $filesystemType): void
    {
        $this->filesystemType = $filesystemType;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLastScan(): ?\DateTimeImmutable
    {
        return $this->lastScan;
    }

    public function markScanned(): void
    {
        $this->lastScan = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getScanStatus(): ?string
    {
        return $this->scanStatus;
    }

    public function setScanStatus(?string $scanStatus): void
    {
        $this->scanStatus = $scanStatus;
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
