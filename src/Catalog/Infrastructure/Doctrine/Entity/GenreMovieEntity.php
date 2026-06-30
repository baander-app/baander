<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'genre_movie')]
#[ORM\UniqueConstraint(name: 'genre_movie_unique', columns: ['genre_id', 'movie_id'])]
#[ORM\Index(name: 'idx_genre_movie_genre_id', columns: ['genre_id'])]
#[ORM\Index(name: 'idx_genre_movie_movie_id', columns: ['movie_id'])]
class GenreMovieEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GenreEntity::class)]
    #[ORM\JoinColumn(name: 'genre_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GenreEntity $genre;

    #[ORM\ManyToOne(targetEntity: MovieEntity::class)]
    #[ORM\JoinColumn(name: 'movie_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MovieEntity $movie;

    public function __construct(
        GenreEntity $genre,
        MovieEntity $movie,
    ) {
        $this->id = new Uuid();
        $this->genre = $genre;
        $this->movie = $movie;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGenre(): GenreEntity
    {
        return $this->genre;
    }

    public function getMovie(): MovieEntity
    {
        return $this->movie;
    }
}
