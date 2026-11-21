<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'movie_person_credit_state')]
class MoviePersonCreditStateEntity
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: MovieEntity::class)]
    #[ORM\JoinColumn(name: 'movie_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MovieEntity $movie;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $castInitialized = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $crewInitialized = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $directorsInitialized = false;

    public function __construct(MovieEntity $movie)
    {
        $this->movie = $movie;
    }

    public function initializeDirectors(): void
    {
        $this->directorsInitialized = true;
    }

    public function directorsInitialized(): bool
    {
        return $this->directorsInitialized;
    }

    public function getMovie(): MovieEntity
    {
        return $this->movie;
    }
}
