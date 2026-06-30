<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'songs')]
#[ORM\UniqueConstraint(name: 'songs_public_id_unique', columns: ['public_id'])]
#[ORM\Index(name: 'idx_songs_album_id', columns: ['album_id'])]
#[ORM\Index(name: 'idx_songs_title_pgroonga', columns: ['title'], flags: ['pgroonga'], options: ['with' => "plugins='token_filters/stem', tokenizer='TokenNgram', normalizer='NormalizerAuto', token_filters='TokenFilterStem'"])]
#[ORM\Index(name: 'idx_songs_title', columns: ['title'])]
#[ORM\Index(name: 'idx_songs_title_id', columns: ['title', 'id'])]
#[ORM\Index(name: 'idx_songs_hash', columns: ['hash'])]
class SongEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: AlbumEntity::class)]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AlbumEntity $album;

    #[ORM\OneToMany(mappedBy: 'song', targetEntity: GenreSongEntity::class)]
    /** @var Collection<int, GenreSongEntity> */
    private Collection $genres;

    #[ORM\Column(type: 'text')]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $path;

    #[ORM\Column(type: 'integer')]
    private int $size;

    #[ORM\Column(type: 'text')]
    private string $mimeType;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $length = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lyrics = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $track = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $disc = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $year = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $hash = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $bitrate = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sampleRate = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $channels = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $codec = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $explicit = false;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $energy = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $danceability = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $valence = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $acousticness = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $instrumentalness = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $liveness = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $spechiness = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $loudness = null;

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
        AlbumEntity $album,
        string $title,
        string $path,
        int $size,
        string $mimeType,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->album = $album;
        $this->genres = new ArrayCollection();
        $this->title = $title;
        $this->path = $path;
        $this->size = $size;
        $this->mimeType = $mimeType;
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

    public function getAlbum(): AlbumEntity
    {
        return $this->album;
    }

    /**
     * @return Collection<int, GenreSongEntity>
     */
    public function getGenres(): Collection
    {
        return $this->genres;
    }

    public function setAlbum(AlbumEntity $album): void
    {
        $this->album = $album;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): void
    {
        $this->mimeType = $mimeType;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLength(): ?float
    {
        return $this->length;
    }

    public function setLength(?float $length): void
    {
        $this->length = $length;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLyrics(): ?string
    {
        return $this->lyrics;
    }

    public function setLyrics(?string $lyrics): void
    {
        $this->lyrics = $lyrics;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTrack(): ?int
    {
        return $this->track;
    }

    public function setTrack(?int $track): void
    {
        $this->track = $track;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDisc(): ?int
    {
        return $this->disc;
    }

    public function setDisc(?int $disc): void
    {
        $this->disc = $disc;
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

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): void
    {
        $this->hash = $hash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBitrate(): ?int
    {
        return $this->bitrate;
    }

    public function setBitrate(?int $bitrate): void
    {
        $this->bitrate = $bitrate;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSampleRate(): ?int
    {
        return $this->sampleRate;
    }

    public function setSampleRate(?int $sampleRate): void
    {
        $this->sampleRate = $sampleRate;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getChannels(): ?int
    {
        return $this->channels;
    }

    public function setChannels(?int $channels): void
    {
        $this->channels = $channels;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCodec(): ?string
    {
        return $this->codec;
    }

    public function setCodec(?string $codec): void
    {
        $this->codec = $codec;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isExplicit(): bool
    {
        return $this->explicit;
    }

    public function setExplicit(bool $explicit): void
    {
        $this->explicit = $explicit;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getEnergy(): ?float
    {
        return $this->energy;
    }

    public function setEnergy(?float $energy): void
    {
        $this->energy = $energy;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDanceability(): ?float
    {
        return $this->danceability;
    }

    public function setDanceability(?float $danceability): void
    {
        $this->danceability = $danceability;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getValence(): ?float
    {
        return $this->valence;
    }

    public function setValence(?float $valence): void
    {
        $this->valence = $valence;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAcousticness(): ?float
    {
        return $this->acousticness;
    }

    public function setAcousticness(?float $acousticness): void
    {
        $this->acousticness = $acousticness;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getInstrumentalness(): ?float
    {
        return $this->instrumentalness;
    }

    public function setInstrumentalness(?float $instrumentalness): void
    {
        $this->instrumentalness = $instrumentalness;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLiveness(): ?float
    {
        return $this->liveness;
    }

    public function setLiveness(?float $liveness): void
    {
        $this->liveness = $liveness;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSpechiness(): ?float
    {
        return $this->spechiness;
    }

    public function setSpechiness(?float $spechiness): void
    {
        $this->spechiness = $spechiness;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLoudness(): ?float
    {
        return $this->loudness;
    }

    public function setLoudness(?float $loudness): void
    {
        $this->loudness = $loudness;
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
}
