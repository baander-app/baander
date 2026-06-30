<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lyrics')]
#[ORM\UniqueConstraint(name: 'lyrics_song_id_unique', columns: ['song_id'])]
#[ORM\UniqueConstraint(name: 'lyrics_lrclib_id_unique', columns: ['lrclib_id'])]
class LyricsEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $songId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $plainLyrics = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $syncedLyrics = null;

    #[ORM\Column(type: 'text')]
    private string $source;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $lrclibId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isInstrumental = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $songId,
        string $source,
        ?Uuid $existingId = null,
    ) {
        $this->id = $existingId ?? $id;
        $this->songId = $songId;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSongId(): Uuid
    {
        return $this->songId;
    }

    public function getPlainLyrics(): ?string
    {
        return $this->plainLyrics;
    }

    public function setPlainLyrics(?string $plainLyrics): void
    {
        $this->plainLyrics = $plainLyrics;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSyncedLyrics(): ?string
    {
        return $this->syncedLyrics;
    }

    public function setSyncedLyrics(?string $syncedLyrics): void
    {
        $this->syncedLyrics = $syncedLyrics;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): void
    {
        $this->sourceUrl = $sourceUrl;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLrclibId(): ?int
    {
        return $this->lrclibId;
    }

    public function setLrclibId(?int $lrclibId): void
    {
        $this->lrclibId = $lrclibId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isInstrumental(): bool
    {
        return $this->isInstrumental;
    }

    public function setInstrumental(bool $isInstrumental): void
    {
        $this->isInstrumental = $isInstrumental;
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
