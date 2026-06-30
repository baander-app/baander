<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'albums')]
#[ORM\UniqueConstraint(name: 'albums_public_id_unique', columns: ['public_id'])]
#[ORM\Index(name: 'idx_albums_library_id', columns: ['library_id'])]
#[ORM\Index(name: 'idx_albums_title_pgroonga', columns: ['title'], flags: ['pgroonga'], options: ['with' => "plugins='token_filters/stem', tokenizer='TokenNgram', normalizer='NormalizerAuto', token_filters='TokenFilterStem'"])]
class AlbumEntity
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

    #[ORM\Column(type: 'text')]
    private string $type;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mbid = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $discogsId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $spotifyId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $year = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $catalogNumber = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $country = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $language = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $disambiguation = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $annotation = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $lockedFields = [];

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $mergedFrom = [];

    #[ORM\ManyToOne(targetEntity: ImageEntity::class)]
    #[ORM\JoinColumn(name: 'cover_image_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ImageEntity $coverImage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        LibraryEntity $library,
        string $title,
        string $type,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->library = $library;
        $this->title = $title;
        $this->type = $type;
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

    public function getLibrary(): LibraryEntity
    {
        return $this->library;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
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

    public function getMbid(): ?string
    {
        return $this->mbid;
    }

    public function setMbid(?string $mbid): void
    {
        $this->mbid = $mbid;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDiscogsId(): ?string
    {
        return $this->discogsId;
    }

    public function setDiscogsId(?string $discogsId): void
    {
        $this->discogsId = $discogsId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSpotifyId(): ?string
    {
        return $this->spotifyId;
    }

    public function setSpotifyId(?string $spotifyId): void
    {
        $this->spotifyId = $spotifyId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): void
    {
        $this->year = $year;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCatalogNumber(): ?string
    {
        return $this->catalogNumber;
    }

    public function setCatalogNumber(?string $catalogNumber): void
    {
        $this->catalogNumber = $catalogNumber;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): void
    {
        $this->barcode = $barcode;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): void
    {
        $this->country = $country;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): void
    {
        $this->language = $language;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDisambiguation(): ?string
    {
        return $this->disambiguation;
    }

    public function setDisambiguation(?string $disambiguation): void
    {
        $this->disambiguation = $disambiguation;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAnnotation(): ?string
    {
        return $this->annotation;
    }

    public function setAnnotation(?string $annotation): void
    {
        $this->annotation = $annotation;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLockedFields(): array
    {
        return $this->lockedFields;
    }

    public function setLockedFields(array $lockedFields): void
    {
        $this->lockedFields = $lockedFields;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array<int, array{id: string, title: string, mergedAt: string}>
     */
    public function getMergedFrom(): array
    {
        return $this->mergedFrom;
    }

    public function setMergedFrom(array $mergedFrom): void
    {
        $this->mergedFrom = $mergedFrom;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCoverImage(): ?ImageEntity
    {
        return $this->coverImage;
    }

    public function setCoverImage(?ImageEntity $coverImage): void
    {
        $this->coverImage = $coverImage;
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
