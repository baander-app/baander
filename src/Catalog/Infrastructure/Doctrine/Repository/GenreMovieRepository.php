<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Infrastructure\Doctrine\Entity\GenreMovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class GenreMovieRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function add(Uuid $genreId, Uuid $movieId): void
    {
        $existing = $this->entityManager
            ->getRepository(GenreMovieEntity::class)
            ->findOneBy(['genre' => $genreId, 'movie' => $movieId]);
        if ($existing !== null) {
            return;
        }

        $genre = $this->entityManager->getRepository(GenreEntity::class)->find($genreId);
        $movie = $this->entityManager->getRepository(MovieEntity::class)->find($movieId);
        if ($genre === null || $movie === null) {
            return;
        }

        $this->entityManager->persist(new GenreMovieEntity($genre, $movie));
        $this->entityManager->flush();
    }

    public function remove(Uuid $genreId, Uuid $movieId): void
    {
        $existing = $this->entityManager
            ->getRepository(GenreMovieEntity::class)
            ->findOneBy(['genre' => $genreId, 'movie' => $movieId]);
        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }
    }

    /**
     * @return Uuid[]
     */
    public function findGenreIdsByMovie(Uuid $movieId): array
    {
        $rows = $this->entityManager->getRepository(GenreMovieEntity::class)->findBy(['movie' => $movieId]);

        return array_map(fn (GenreMovieEntity $row) => $row->getGenre()->getId(), $rows);
    }

    /**
     * @return Uuid[]
     */
    public function findMovieIdsByGenre(Uuid $genreId): array
    {
        $rows = $this->entityManager->getRepository(GenreMovieEntity::class)->findBy(['genre' => $genreId]);

        return array_map(fn (GenreMovieEntity $row) => $row->getMovie()->getId(), $rows);
    }
}
