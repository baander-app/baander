<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'artists')]
#[ORM\UniqueConstraint(name: 'artists_public_id_unique', columns: ['public_id'])]
#[ORM\Index(name: 'idx_artists_name_pgroonga', columns: ['name'], flags: ['pgroonga'], options: ['with' => "plugins='token_filters/stem', tokenizer='TokenNgram', normalizer='NormalizerAuto', token_filters='TokenFilterStem'"])]
class ArtistEntity
{
    #[ORM\ManyToOne(targetEntity: \App\Media\Infrastructure\Doctrine\Entity\ImageEntity::class)]
    #[ORM\JoinColumn(name: 'cover_image_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ImageEntity $coverImage = null;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $country = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lifeSpanBegin = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lifeSpanEnd = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $disambiguation = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sortName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $biography = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mbid = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $discogsId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $spotifyId = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $lockedFields = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        string $name,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->name = $name;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
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

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): void
    {
        $this->gender = $gender;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLifeSpanBegin(): ?\DateTimeImmutable
    {
        return $this->lifeSpanBegin;
    }

    public function setLifeSpanBegin(?\DateTimeImmutable $lifeSpanBegin): void
    {
        $this->lifeSpanBegin = $lifeSpanBegin;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLifeSpanEnd(): ?\DateTimeImmutable
    {
        return $this->lifeSpanEnd;
    }

    public function setLifeSpanEnd(?\DateTimeImmutable $lifeSpanEnd): void
    {
        $this->lifeSpanEnd = $lifeSpanEnd;
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

    public function getSortName(): ?string
    {
        return $this->sortName;
    }

    public function setSortName(?string $sortName): void
    {
        $this->sortName = $sortName;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBiography(): ?string
    {
        return $this->biography;
    }

    public function setBiography(?string $biography): void
    {
        $this->biography = $biography;
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

    public function getLockedFields(): array
    {
        return $this->lockedFields;
    }

    public function setLockedFields(array $lockedFields): void
    {
        $this->lockedFields = $lockedFields;
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

    public function getCoverImage(): ?ImageEntity
    {
        return $this->coverImage;
    }

    public function setCoverImage(?ImageEntity $coverImage): void
    {
        $this->coverImage = $coverImage;
    }
}
