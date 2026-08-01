<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'videos')]
#[ORM\UniqueConstraint(name: 'videos_public_id_unique', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'videos_hash_unique', columns: ['hash'])]
class VideoEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'text')]
    private string $path;

    #[ORM\Column(type: 'text')]
    private string $hash;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $duration = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $height = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $videoBitrate = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $framerate = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $probe = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        string $path,
        string $hash,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->path = $path;
        $this->hash = $hash;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
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

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setHash(string $hash): void
    {
        $this->hash = $hash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): void
    {
        $this->duration = $duration;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): void
    {
        $this->height = $height;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): void
    {
        $this->width = $width;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getVideoBitrate(): ?int
    {
        return $this->videoBitrate;
    }

    public function setVideoBitrate(?int $videoBitrate): void
    {
        $this->videoBitrate = $videoBitrate;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFramerate(): ?int
    {
        return $this->framerate;
    }

    public function setFramerate(?int $framerate): void
    {
        $this->framerate = $framerate;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getProbe(): array
    {
        return $this->probe;
    }

    public function setProbe(array $probe): void
    {
        $this->probe = $probe;
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
