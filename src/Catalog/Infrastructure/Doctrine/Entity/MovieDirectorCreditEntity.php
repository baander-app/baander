<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'movie_director_credits')]
#[ORM\Index(name: 'idx_movie_director_credits_director_id', columns: ['director_id'])]
class MovieDirectorCreditEntity
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: MovieEntity::class)]
    #[ORM\JoinColumn(name: 'movie_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MovieEntity $movie;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: MovieDirectorEntity::class)]
    #[ORM\JoinColumn(name: 'director_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MovieDirectorEntity $director;

    public function __construct(MovieEntity $movie, MovieDirectorEntity $director)
    {
        $this->movie = $movie;
        $this->director = $director;
    }

    public function getMovie(): MovieEntity
    {
        return $this->movie;
    }
    public function getDirector(): MovieDirectorEntity
    {
        return $this->director;
    }
}
