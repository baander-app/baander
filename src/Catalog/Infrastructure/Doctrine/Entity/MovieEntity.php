<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'movies')]
#[ORM\UniqueConstraint(name: 'movies_public_id_unique', columns: ['public_id'])]
#[ORM\Index(name: 'idx_movies_library_id', columns: ['library_id'])]
#[ORM\Index(name: 'idx_movies_title_pgroonga', columns: ['title'], flags: ['pgroonga'], options: ['with' => "plugins='token_filters/stem', tokenizer='TokenNgram', normalizer='NormalizerAuto', token_filters='TokenFilterStem'"])]
class MovieEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: LibraryEntity::class)]
    #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LibraryEntity $library;

    #[ORM\Column(type: 'text')]
    private string $title;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $year = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tmdbId = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imdbId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $overview = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $posterUrl = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $backdropUrl = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $runtime = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rating = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $originalLanguage = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tmdbCollectionId = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $collectionName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        LibraryEntity $library,
        string $title,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->library = $library;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getLibrary(): LibraryEntity { return $this->library; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; $this->updatedAt = new \DateTimeImmutable(); }
    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $year): void { $this->year = $year; $this->updatedAt = new \DateTimeImmutable(); }
    public function getSummary(): ?string { return $this->summary; }
    public function setSummary(?string $summary): void { $this->summary = $summary; $this->updatedAt = new \DateTimeImmutable(); }
    public function getTmdbId(): ?int { return $this->tmdbId; }
    public function setTmdbId(?int $tmdbId): void { $this->tmdbId = $tmdbId; $this->updatedAt = new \DateTimeImmutable(); }
    public function getImdbId(): ?string { return $this->imdbId; }
    public function setImdbId(?string $imdbId): void { $this->imdbId = $imdbId; $this->updatedAt = new \DateTimeImmutable(); }
    public function getOverview(): ?string { return $this->overview; }
    public function setOverview(?string $overview): void { $this->overview = $overview; $this->updatedAt = new \DateTimeImmutable(); }
    public function getTagline(): ?string { return $this->tagline; }
    public function setTagline(?string $tagline): void { $this->tagline = $tagline; $this->updatedAt = new \DateTimeImmutable(); }
    public function getPosterUrl(): ?string { return $this->posterUrl; }
    public function setPosterUrl(?string $posterUrl): void { $this->posterUrl = $posterUrl; $this->updatedAt = new \DateTimeImmutable(); }
    public function getBackdropUrl(): ?string { return $this->backdropUrl; }
    public function setBackdropUrl(?string $backdropUrl): void { $this->backdropUrl = $backdropUrl; $this->updatedAt = new \DateTimeImmutable(); }
    public function getRuntime(): ?int { return $this->runtime; }
    public function setRuntime(?int $runtime): void { $this->runtime = $runtime; $this->updatedAt = new \DateTimeImmutable(); }
    public function getRating(): ?float { return $this->rating; }
    public function setRating(?float $rating): void { $this->rating = $rating; $this->updatedAt = new \DateTimeImmutable(); }
    public function getOriginalLanguage(): ?string { return $this->originalLanguage; }
    public function setOriginalLanguage(?string $originalLanguage): void { $this->originalLanguage = $originalLanguage; $this->updatedAt = new \DateTimeImmutable(); }
    public function getTmdbCollectionId(): ?int { return $this->tmdbCollectionId; }
    public function setTmdbCollectionId(?int $tmdbCollectionId): void { $this->tmdbCollectionId = $tmdbCollectionId; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCollectionName(): ?string { return $this->collectionName; }
    public function setCollectionName(?string $collectionName): void { $this->collectionName = $collectionName; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
