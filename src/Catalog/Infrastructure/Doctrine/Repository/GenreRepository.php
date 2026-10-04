<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\Model\GenreState;
use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Catalog\Domain\Repository\GenreRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreMovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Query\CatalogReadScopeQuery;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Pure domain repository for genres.
 */
final class GenreRepository implements GenreRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?GenreReadView
    {
        $entities = $this->visibleQuery($scope)
            ->andWhere('g.id = :id')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getResult();

        return $this->toVisibleReadViews($entities, $scope)[0] ?? null;
    }

    public function findVisibleBySlug(string $slug, LibraryReadScope $scope): ?GenreReadView
    {
        $entities = $this->visibleQuery($scope)
            ->andWhere('g.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getResult();

        return $this->toVisibleReadViews($entities, $scope)[0] ?? null;
    }

    public function findVisibleChildren(Uuid $parentId, LibraryReadScope $scope): array
    {
        if ($this->findVisibleByUuid($parentId, $scope) === null) {
            return [];
        }

        $entities = $this->visibleQuery($scope)
            ->andWhere('g.parent = :parentId')
            ->setParameter('parentId', $parentId)
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->toVisibleReadViews($entities, $scope);
    }

    public function findVisibleRootGenres(LibraryReadScope $scope): array
    {
        $qb = $this->visibleQuery($scope);
        if ($scope->isUnrestricted()) {
            $qb->andWhere('g.parent IS NULL');
        } else {
            $visibleParents = $this->visibleQuery($scope, 'visible_parent')
                ->select('visible_parent.id')
                ->andWhere('visible_parent.id = g.parent');
            $qb->andWhere('g.parent IS NULL OR NOT EXISTS (' . $visibleParents->getDQL() . ')');
        }
        $entities = $qb->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->toVisibleReadViews($entities, $scope);
    }

    public function findAllVisible(LibraryReadScope $scope): array
    {
        $entities = $this->visibleQuery($scope)
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->toVisibleReadViews($entities, $scope);
    }

    public function countVisible(LibraryReadScope $scope): int
    {
        return (int) $this->visibleQuery($scope)
            ->select('COUNT(g.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function visibleQuery(LibraryReadScope $scope, string $alias = 'g'): QueryBuilder
    {
        $qb = $this->entityManager
            ->getRepository(GenreEntity::class)
            ->createQueryBuilder($alias);
        CatalogReadScopeQuery::apply($qb, $scope, 'genre', $alias);

        return $qb;
    }

    /**
     * @param GenreEntity[] $entities
     * @return GenreReadView[]
     */
    private function toVisibleReadViews(array $entities, LibraryReadScope $scope): array
    {
        $visibleParentIds = [];
        if (!$scope->isUnrestricted()) {
            $parentIds = [];
            foreach ($entities as $entity) {
                $parentId = $entity->getParent()?->getId();
                if ($parentId !== null) {
                    $parentIds[$parentId->toString()] = $parentId;
                }
            }
            if ($parentIds !== []) {
                $visibleParents = $this->visibleQuery($scope)
                    ->andWhere('g.id IN (:parentIds)')
                    ->setParameter('parentIds', array_values($parentIds))
                    ->getQuery()
                    ->getResult();
                foreach ($visibleParents as $parent) {
                    $visibleParentIds[$parent->getId()->toString()] = true;
                }
            }
        }

        return array_map(static function (GenreEntity $entity) use ($scope, $visibleParentIds): GenreReadView {
            $parentId = $entity->getParent()?->getId();
            if ($parentId !== null && !$scope->isUnrestricted() && !isset($visibleParentIds[$parentId->toString()])) {
                $parentId = null;
            }

            return new GenreReadView(
                $entity->getId(),
                $entity->getName(),
                $entity->getSlug(),
                $parentId,
                $entity->getMbid(),
            );
        }, $entities);
    }

    public function save(Genre $genre): void
    {
        $entity = $this->findEntityOrCreate($genre);
        $this->syncToEntity($genre, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(Genre $genre): void
    {
        $entity = $this->findEntityOrCreate($genre);
        $this->syncToEntity($genre, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Genre
    {
        $entity = $this->entityManager
            ->getRepository(GenreEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findBySlug(string $slug): ?Genre
    {
        $entity = $this->entityManager
            ->getRepository(GenreEntity::class)
            ->findOneBy(['slug' => $slug]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /**
     * @return Genre[]
     */
    public function findChildren(Uuid $parentId): array
    {
        $entities = $this->entityManager
            ->getRepository(GenreEntity::class)
            ->findBy(
                ['parent' => $parentId],
                ['name' => 'ASC'],
            );

        return array_map(fn (GenreEntity $entity) => $this->toDomain($entity), $entities);
    }

    /**
     * @return Genre[]
     */
    public function findRootGenres(): array
    {
        $entities = $this->entityManager
            ->getRepository(GenreEntity::class)
            ->findBy(
                ['parent' => null],
                ['name' => 'ASC'],
            );

        return array_map(fn (GenreEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function count(): int
    {
        return (int) $this->entityManager
            ->getRepository(GenreEntity::class)
            ->count([]);
    }

    public function delete(Genre $genre): void
    {
        $entity = $this->entityManager
            ->getRepository(GenreEntity::class)
            ->find($genre->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function findAll(): array
    {
        $entities = $this->entityManager
            ->getRepository(GenreEntity::class)
            ->findBy([], ['name' => 'ASC']);

        return array_map(fn (GenreEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function isDescendantOf(Uuid $parentId, Uuid $childId): bool
    {
        $visited = [];
        $currentId = $childId;

        while ($currentId !== null) {
            if ($currentId->equals($parentId)) {
                return true;
            }

            $hash = $currentId->toString();
            if (isset($visited[$hash])) {
                break;
            }
            $visited[$hash] = true;

            $entity = $this->entityManager
                ->getRepository(GenreEntity::class)
                ->find($currentId);

            $currentId = $entity?->getParent()?->getId();
        }

        return false;
    }

    public function findOrCreateByName(string $name): Genre
    {
        $slug = $this->generateSlug($name);
        $normalizedName = trim($name);

        // Try slug lookup first
        $existing = $this->findBySlug($slug);
        if ($existing !== null) {
            return $existing;
        }

        // Try case-insensitive name lookup
        $qb = $this->entityManager->getRepository(GenreEntity::class)->createQueryBuilder('g');
        $result = $qb->where($qb->expr()->eq($qb->expr()->lower('g.name'), ':name'))
            ->setParameter('name', strtolower($normalizedName))
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if ($result !== []) {
            return $this->toDomain($result[0]);
        }

        // Create new genre
        $genre = Genre::create(ucfirst($normalizedName), $slug);
        $entity = new GenreEntity(
            $genre->getName(),
            $genre->getSlug(),
            null,
            $genre->getMbid(),
        );
        $this->entityManager->persist($entity);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            $this->entityManager->clear();
            $existing = $this->findBySlug($slug);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        return $this->toDomain($entity);
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'genre';
    }

    // --- Internal ---

    private function findEntityOrCreate(Genre $genre): GenreEntity
    {
        $existing = $this->entityManager
            ->getRepository(GenreEntity::class)
            ->find($genre->getId());

        if ($existing !== null) {
            return $existing;
        }

        $parentEntity = $genre->getParent() !== null
            ? $this->entityManager->getRepository(GenreEntity::class)->find($genre->getParent())
            : null;

        return new GenreEntity(
            $genre->getName(),
            $genre->getSlug(),
            $parentEntity,
            $genre->getMbid(),
            id: $genre->getId(),
        );
    }

    private function toDomain(GenreEntity $entity): Genre
    {
        return Genre::reconstitute(new GenreState(
            id: $entity->getId(),
            name: $entity->getName(),
            slug: $entity->getSlug(),
            mbid: $entity->getMbid(),
            parent: $entity->getParent()?->getId(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(Genre $genre, GenreEntity $entity): void
    {
        $entity->setName($genre->getName());
        $entity->setSlug($genre->getSlug());
        $entity->setMbid($genre->getMbid());

        if ($genre->getParent() !== null) {
            $parentEntity = $this->entityManager
                ->getRepository(GenreEntity::class)
                ->find($genre->getParent());
            $entity->setParent($parentEntity);
        } else {
            $entity->setParent(null);
        }
    }

    public function addSongToGenre(Uuid $genreId, Uuid $songId): void
    {
        $genreEntity = $this->entityManager->getRepository(GenreEntity::class)->find($genreId);
        $songEntity = $this->entityManager->getRepository(SongEntity::class)->find($songId);

        if ($genreEntity === null || $songEntity === null) {
            return;
        }

        $existing = $this->entityManager->getRepository(GenreSongEntity::class)->findOneBy([
            'genre' => $genreEntity,
            'song' => $songEntity,
        ]);

        if ($existing !== null) {
            return;
        }

        $this->entityManager->persist(new GenreSongEntity($genreEntity, $songEntity));
        $this->entityManager->flush();
    }

    public function removeSongFromGenre(Uuid $genreId, Uuid $songId): void
    {
        $genreEntity = $this->entityManager->getRepository(GenreEntity::class)->find($genreId);
        $songEntity = $this->entityManager->getRepository(SongEntity::class)->find($songId);

        if ($genreEntity === null || $songEntity === null) {
            return;
        }

        $existing = $this->entityManager->getRepository(GenreSongEntity::class)->findOneBy([
            'genre' => $genreEntity,
            'song' => $songEntity,
        ]);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }
    }

    public function addAlbumToGenre(Uuid $genreId, Uuid $albumId): void
    {
        $genreEntity = $this->entityManager->getRepository(GenreEntity::class)->find($genreId);
        $albumEntity = $this->entityManager->getRepository(AlbumEntity::class)->find($albumId);

        if ($genreEntity === null || $albumEntity === null) {
            return;
        }

        $existing = $this->entityManager->getRepository(GenreAlbumEntity::class)->findOneBy([
            'genre' => $genreEntity,
            'album' => $albumEntity,
        ]);

        if ($existing !== null) {
            return;
        }

        $this->entityManager->persist(new GenreAlbumEntity($genreEntity, $albumEntity));
        $this->entityManager->flush();
    }

    public function removeAlbumFromGenre(Uuid $genreId, Uuid $albumId): void
    {
        $genreEntity = $this->entityManager->getRepository(GenreEntity::class)->find($genreId);
        $albumEntity = $this->entityManager->getRepository(AlbumEntity::class)->find($albumId);

        if ($genreEntity === null || $albumEntity === null) {
            return;
        }

        $existing = $this->entityManager->getRepository(GenreAlbumEntity::class)->findOneBy([
            'genre' => $genreEntity,
            'album' => $albumEntity,
        ]);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }
    }

    public function addMovieToGenre(Uuid $genreId, Uuid $movieId): void
    {
        $genreEntity = $this->entityManager->getRepository(GenreEntity::class)->find($genreId);
        $movieEntity = $this->entityManager->getRepository(MovieEntity::class)->find($movieId);

        if ($genreEntity === null || $movieEntity === null) {
            return;
        }

        $existing = $this->entityManager->getRepository(GenreMovieEntity::class)->findOneBy([
            'genre' => $genreEntity,
            'movie' => $movieEntity,
        ]);

        if ($existing !== null) {
            return;
        }

        $this->entityManager->persist(new GenreMovieEntity($genreEntity, $movieEntity));
        $this->entityManager->flush();
    }

    public function removeMovieFromGenre(Uuid $genreId, Uuid $movieId): void
    {
        $genreEntity = $this->entityManager->getRepository(GenreEntity::class)->find($genreId);
        $movieEntity = $this->entityManager->getRepository(MovieEntity::class)->find($movieId);

        if ($genreEntity === null || $movieEntity === null) {
            return;
        }

        $existing = $this->entityManager->getRepository(GenreMovieEntity::class)->findOneBy([
            'genre' => $genreEntity,
            'movie' => $movieEntity,
        ]);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }
    }
}
