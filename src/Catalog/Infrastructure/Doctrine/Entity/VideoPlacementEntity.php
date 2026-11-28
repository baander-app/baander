<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Catalog\Domain\Model\VideoPlacementState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_placements')]
#[ORM\UniqueConstraint(name: 'uniq_video_placements_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_video_placements_library_id_video_id', columns: ['library_id', 'video_id'])]
#[ORM\Index(name: 'idx_video_placements_video_id', columns: ['video_id'])]
#[ORM\Index(name: 'idx_video_placements_series_id', columns: ['series_id'])]
#[ORM\Index(name: 'idx_video_placements_movie_id', columns: ['movie_id'])]
class VideoPlacementEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: 'public_id')]
    public PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: LibraryEntity::class)]
    #[ORM\JoinColumn(name: 'library_id', nullable: false, onDelete: 'CASCADE')]
    public LibraryEntity $library;

    #[ORM\ManyToOne(targetEntity: VideoEntity::class)]
    #[ORM\JoinColumn(name: 'video_id', nullable: false, onDelete: 'RESTRICT')]
    public VideoEntity $video;

    #[ORM\ManyToOne(targetEntity: TvSeriesEntity::class)]
    #[ORM\JoinColumn(name: 'series_id', nullable: true, onDelete: 'CASCADE')]
    public ?TvSeriesEntity $series = null;

    #[ORM\ManyToOne(targetEntity: MovieEntity::class)]
    #[ORM\JoinColumn(name: 'movie_id', nullable: true, onDelete: 'CASCADE')]
    public ?MovieEntity $movie = null;

    #[ORM\Column(type: 'text')]
    public string $relativePath;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $rawParse = [];

    #[ORM\Column(type: 'text', nullable: true, enumType: ExtraKind::class)]
    public ?ExtraKind $extraKind = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public bool $assigned = false;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $reviewReason = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $reviewCheckedAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    public int $sortOrder = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;

    /** @var Collection<int, VideoPlacementEpisodeEntity> */
    #[ORM\OneToMany(targetEntity: VideoPlacementEpisodeEntity::class, mappedBy: 'placement', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    public Collection $episodes;

    public function __construct(LibraryEntity $library, VideoEntity $video, VideoPlacementState $state)
    {
        $this->id = $state->id;
        $this->publicId = $state->publicId;
        $this->library = $library;
        $this->video = $video;
        $this->createdAt = $state->createdAt;
        $this->episodes = new ArrayCollection();
        $this->apply($state);
    }

    public function state(): VideoPlacementState
    {
        return new VideoPlacementState(
            id: $this->id,
            publicId: $this->publicId,
            libraryId: $this->library->getId(),
            videoId: $this->video->getId(),
            relativePath: $this->relativePath,
            rawParse: $this->rawParse,
            seriesId: $this->series?->id,
            movieId: $this->movie?->getId(),
            extraKind: $this->extraKind,
            episodeIds: array_map(
                static fn (VideoPlacementEpisodeEntity $link): Uuid => $link->episode->id,
                array_values($this->episodes->toArray()),
            ),
            assigned: $this->assigned,
            reviewReason: $this->reviewReason,
            sortOrder: $this->sortOrder,
            createdAt: $this->createdAt,
            reviewCheckedAt: $this->reviewCheckedAt,
        );
    }

    public function apply(VideoPlacementState $state): void
    {
        $this->relativePath = $state->relativePath;
        $this->rawParse = $state->rawParse;
        $this->extraKind = $state->extraKind;
        $this->assigned = $state->assigned;
        $this->reviewReason = $state->reviewReason;
        $this->reviewCheckedAt = $state->reviewCheckedAt;
        $this->sortOrder = $state->sortOrder;
    }
}
