<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'movie_collections')]
#[ORM\UniqueConstraint(name: 'tmdb_collection_id_unique', columns: ['tmdb_collection_id'])]
class MovieCollectionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'integer', unique: true)]
    private int $tmdbCollectionId;

    #[ORM\Column(type: 'string')]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $overview = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $posterPath = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $backdropPath = null;

    public function __construct(int $tmdbCollectionId, string $name, ?Uuid $id = null)
    {
        $this->id = $id ?? new Uuid();
        $this->tmdbCollectionId = $tmdbCollectionId;
        $this->name = $name;
    }

    public function getId(): Uuid { return $this->id; }
    public function getTmdbCollectionId(): int { return $this->tmdbCollectionId; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getOverview(): ?string { return $this->overview; }
    public function setOverview(?string $overview): void { $this->overview = $overview; }
    public function getPosterPath(): ?string { return $this->posterPath; }
    public function setPosterPath(?string $posterPath): void { $this->posterPath = $posterPath; }
    public function getBackdropPath(): ?string { return $this->backdropPath; }
    public function setBackdropPath(?string $backdropPath): void { $this->backdropPath = $backdropPath; }
}
