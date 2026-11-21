<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Catalog\Domain\Model\TvSeriesState;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tv_series')]
#[ORM\UniqueConstraint(name: 'uniq_tv_series_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tv_series_library_id_tmdb_id', columns: ['library_id', 'tmdb_id'])]
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

    #[ORM\Column(name: 'tmdb_id', type: 'integer')]
    public int $tmdbId;

    #[ORM\Column(type: 'text')]
    public string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $overview = null;

    #[ORM\Column(name: 'poster_url', type: 'text', nullable: true)]
    public ?string $posterUrl = null;

    public function __construct(LibraryEntity $library, TvSeriesState $state)
    {
        $this->id = $state->id;
        $this->publicId = $state->publicId;
        $this->library = $library;
        $this->apply($state);
    }
    public function state(): TvSeriesState
    {
        return new TvSeriesState(
            id: $this->id,
            publicId: $this->publicId,
            libraryId: $this->library->getId(),
            tmdbId: $this->tmdbId,
            name: $this->name,
            overview: $this->overview,
            posterUrl: $this->posterUrl,
        );
    }

    public function apply(TvSeriesState $state): void
    {
        $this->tmdbId = $state->tmdbId;
        $this->name = $state->name;
        $this->overview = $state->overview;
        $this->posterUrl = $state->posterUrl;
    }
}
