<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Catalog\Domain\Model\TvSeriesState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tv_series')]
#[ORM\UniqueConstraint(name: 'uniq_tv_series_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tv_series_library_id_folder_path', columns: ['library_id', 'folder_path'])]
class TvSeriesEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: 'public_id')]
    public PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: LibraryEntity::class)]
    #[ORM\JoinColumn(name: 'library_id', nullable: false, onDelete: 'CASCADE')]
    public LibraryEntity $library;

    #[ORM\Column(type: 'text')]
    public string $folderPath;

    #[ORM\Column(type: 'text')]
    public string $name;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $year = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $matchedProvider = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $providerId = null;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $externalIds = [];

    #[ORM\Column(type: 'text', options: ['default' => 'aired'])]
    public string $ordering = 'aired';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public bool $handMatched = false;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $overview = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $posterUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $backdropUrl = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $updatedAt;

    /** @var array<string, string|int|null> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $providerFields = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $lockedFields = [];

    public function __construct(LibraryEntity $library, TvSeriesState $state)
    {
        $this->id = $state->id;
        $this->publicId = $state->publicId;
        $this->library = $library;
        $this->createdAt = $state->createdAt;
        $this->apply($state);
    }

    public function state(): TvSeriesState
    {
        return new TvSeriesState(
            id: $this->id,
            publicId: $this->publicId,
            libraryId: $this->library->getId(),
            folderPath: $this->folderPath,
            name: $this->name,
            year: $this->year,
            matchedProvider: $this->matchedProvider,
            providerId: $this->providerId,
            externalIds: $this->externalIds,
            ordering: $this->ordering,
            handMatched: $this->handMatched,
            overview: $this->overview,
            posterUrl: $this->posterUrl,
            backdropUrl: $this->backdropUrl,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            providerFields: $this->providerFields,
            lockedFields: $this->lockedFields,
        );
    }

    public function apply(TvSeriesState $state): void
    {
        $this->providerFields = $state->providerFields;
        $this->lockedFields = $state->lockedFields;
        $this->folderPath = $state->folderPath;
        $this->name = $state->name;
        $this->year = $state->year;
        $this->matchedProvider = $state->matchedProvider;
        $this->providerId = $state->providerId;
        $this->externalIds = $state->externalIds;
        $this->ordering = $state->ordering;
        $this->handMatched = $state->handMatched;
        $this->overview = $state->overview;
        $this->posterUrl = $state->posterUrl;
        $this->backdropUrl = $state->backdropUrl;
        $this->updatedAt = $state->updatedAt;
    }
}
