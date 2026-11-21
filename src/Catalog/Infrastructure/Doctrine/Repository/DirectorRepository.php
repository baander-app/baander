<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Catalog\Domain\Repository\DirectorRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieDirectorCreditEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieDirectorEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\Query\CatalogReadScopeQuery;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use LogicException;

final readonly class DirectorRepository implements DirectorRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        $query = $this->visibleQuery($scope);
        self::filterText($query, 'director.name', $options);
        $total = (int) (clone $query)
            ->select('COUNT(DISTINCT director.id)')
            ->getQuery()
            ->getSingleScalarResult();
        $rows = $query
            ->select('director', 'COUNT(DISTINCT visible.id) AS movieCount')
            ->groupBy('director.id')
            ->orderBy('director.name', 'ASC')
            ->addOrderBy('director.id', 'ASC')
            ->setFirstResult($options->getOffset())
            ->setMaxResults($options->getLimit())
            ->getQuery()
            ->getResult();

        return SearchResult::create(array_map(self::readView(...), $rows), $total);
    }

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?DirectorReadView
    {
        $row = $this->visibleQuery($scope)
            ->select('director', 'COUNT(DISTINCT visible.id) AS movieCount')
            ->andWhere('director.publicId = :publicId')
            ->setParameter('publicId', $publicId)
            ->groupBy('director.id')
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : self::readView($row);
    }

    public function searchMovieIdsVisible(PublicId $publicId, SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        $query = $this->visibleQuery($scope)
            ->andWhere('director.publicId = :publicId')
            ->setParameter('publicId', $publicId);
        self::filterText($query, 'visible.title', $options);
        $total = (int) (clone $query)
            ->select('COUNT(DISTINCT visible.id)')
            ->getQuery()
            ->getSingleScalarResult();
        $rows = $query
            ->select('visible.id AS movieId', 'visible.title AS movieTitle')
            ->distinct()
            ->orderBy('visible.title', 'ASC')
            ->addOrderBy('visible.id', 'ASC')
            ->setFirstResult($options->getOffset())
            ->setMaxResults($options->getLimit())
            ->getQuery()
            ->getArrayResult();
        $ids = array_map(static fn(array $row): Uuid => $row['movieId'] instanceof Uuid
            ? $row['movieId']
            : Uuid::fromString($row['movieId']), $rows);

        return SearchResult::create($ids, $total);
    }

    public function syncMovieDirectors(Uuid $movieId, array $credits, bool $forceUpdate = false): bool
    {
        $distinct = [];
        $keys = ['movie-director-movie:' . $movieId->toString()];
        foreach ($credits as $credit) {
            $distinct[$credit->tmdbId] = $credit;
            $keys[] = 'movie-director-person:' . $credit->tmdbId;
        }
        ImportIdentityLock::acquire($this->entityManager->getConnection(), $keys);
        $movie = $this->entityManager->find(MovieEntity::class, $movieId);
        if ($movie === null) {
            throw new LogicException('Cannot credit a movie that has not been persisted.');
        }
        $existing = $this->entityManager->getRepository(MovieDirectorCreditEntity::class)->findBy(['movie' => $movie]);
        if (!$forceUpdate && $existing !== []) {
            return false;
        }
        $links = [];
        foreach ($existing as $link) {
            $links[$link->getDirector()->getTmdbId()] = $link;
        }
        $obsolete = [];
        foreach ($links as $tmdbId => $link) {
            if (!isset($distinct[$tmdbId])) {
                $obsolete[] = $link;
            }
        }
        if ($obsolete !== []) {
            // ORM flattens association identifiers into strings, which its delete persister
            // cannot bind through the UUID value-object type. Typed DQL preserves that type
            // and removes the obsolete composite keys in one statement.
            $directorIds = array_map(static fn(MovieDirectorCreditEntity $link): string => $link->getDirector()->getId()->toString(), $obsolete);
            $this->entityManager->createQueryBuilder()
                ->delete(MovieDirectorCreditEntity::class, 'obsolete')
                ->where('IDENTITY(obsolete.movie) = :movieId')
                ->andWhere('IDENTITY(obsolete.director) IN (:directorIds)')
                ->setParameter('movieId', $movieId, 'uuid')
                ->setParameter('directorIds', $directorIds, ArrayParameterType::STRING)
                ->getQuery()
                ->execute();
            foreach ($obsolete as $link) {
                $this->entityManager->detach($link);
            }
        }
        foreach ($distinct as $tmdbId => $credit) {
            // Read after the transaction locks were granted, refreshing an identity already
            // managed by an earlier batch so concurrent committed metadata is not overwritten.
            $director = $this->entityManager->createQueryBuilder()
                ->select('person')
                ->from(MovieDirectorEntity::class, 'person')
                ->where('person.tmdbId = :tmdbId')
                ->setParameter('tmdbId', $tmdbId)
                ->getQuery()
                ->setHint(Query::HINT_REFRESH, true)
                ->getOneOrNullResult();
            if ($director === null) {
                $director = new MovieDirectorEntity($credit);
                $this->entityManager->persist($director);
            } else {
                $director->update($credit);
            }
            if (!isset($links[$tmdbId])) {
                $this->entityManager->persist(new MovieDirectorCreditEntity($movie, $director));
            }
        }
        $this->entityManager->flush();

        return true;
    }

    private function visibleQuery(LibraryReadScope $scope): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('director')
            ->from(MovieDirectorEntity::class, 'director')
            ->innerJoin(MovieDirectorCreditEntity::class, 'credit', 'WITH', 'credit.director = director')
            ->innerJoin('credit.movie', 'visible');
        CatalogReadScopeQuery::apply($query, $scope, 'movie', 'visible');

        return $query;
    }

    private static function filterText(QueryBuilder $query, string $field, SearchOptions $options): void
    {
        if (!$options->hasQuery()) {
            return;
        }
        $text = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($options->getQuery()));
        $query->andWhere("LOWER($field) LIKE :query ESCAPE '!'")
            ->setParameter('query', '%' . $text . '%');
    }

    /** @param array{0: MovieDirectorEntity, movieCount: int|string} $row */
    private static function readView(array $row): DirectorReadView
    {
        $director = $row[0];

        return new DirectorReadView(
            publicId: $director->getPublicId(),
            name: $director->getName(),
            tmdbId: $director->getTmdbId(),
            profileUrl: $director->getProfileUrl(),
            movieCount: (int) $row['movieCount'],
        );
    }
}
