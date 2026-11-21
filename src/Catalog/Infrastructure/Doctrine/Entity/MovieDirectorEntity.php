<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Catalog\Domain\ValueObject\DirectorCredit;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'movie_directors')]
#[ORM\UniqueConstraint(name: 'uniq_movie_directors_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_movie_directors_tmdb_id', columns: ['tmdb_id'])]
class MovieDirectorEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'integer')]
    private int $tmdbId;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $profileUrl;

    public function __construct(DirectorCredit $credit)
    {
        $this->id = Uuid::v7();
        $this->publicId = new PublicId();
        $this->tmdbId = $credit->tmdbId;
        $this->update($credit);
    }

    public function update(DirectorCredit $credit): void
    {
        $this->name = $credit->name;
        $this->profileUrl = $credit->profileUrl;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }
    public function getTmdbId(): int
    {
        return $this->tmdbId;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getProfileUrl(): ?string
    {
        return $this->profileUrl;
    }
}
