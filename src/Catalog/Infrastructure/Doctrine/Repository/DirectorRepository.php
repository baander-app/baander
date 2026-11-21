<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\ReadModel\DirectorReadView;
use App\Catalog\Domain\Repository\DirectorRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MoviePersonCreditEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MoviePersonCreditStateEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonExternalIdEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonIdAliasEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\PersonWriteLock;
use App\Catalog\Infrastructure\Doctrine\Query\CatalogReadScopeQuery;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\ORM\EntityManagerInterface;
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
            ->select('director', 'MIN(identifier.externalId) AS tmdbId', 'COUNT(DISTINCT visible.id) AS movieCount')
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
            ->select('director', 'MIN(identifier.externalId) AS tmdbId', 'COUNT(DISTINCT visible.id) AS movieCount')
            ->andWhere(
                'director.publicId = :publicId OR director.id IN (SELECT IDENTITY(alias.person) FROM '
                . PersonIdAliasEntity::class . ' alias WHERE alias.publicId = :publicId)',
            )
            ->setParameter('publicId', $publicId)
            ->groupBy('director.id')
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : self::readView($row);
    }

    public function searchMovieIdsVisible(PublicId $publicId, SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        $query = $this->visibleQuery($scope)
            ->andWhere(
                'director.publicId = :publicId OR director.id IN (SELECT IDENTITY(alias.person) FROM '
                . PersonIdAliasEntity::class . ' alias WHERE alias.publicId = :publicId)',
            )
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
        PersonWriteLock::shared($this->entityManager->getConnection());
        $distinct = [];
        $keys = ['person-work:movie:' . $movieId->toString()];
        foreach ($credits as $credit) {
            $distinct[$credit->tmdbId] = $credit;
            $keys[] = 'person-external:tmdb_person:' . $credit->tmdbId;
        }
        ImportIdentityLock::acquire($this->entityManager->getConnection(), $keys);
        $movie = $this->entityManager->find(MovieEntity::class, $movieId);
        if ($movie === null) {
            throw new LogicException('Cannot credit a movie that has not been persisted.');
        }
        $state = $this->entityManager->find(MoviePersonCreditStateEntity::class, $movieId);
        if ($state !== null) {
            $this->entityManager->refresh($state);
            if (!$forceUpdate && $state->directorsInitialized()) {
                return false;
            }
        } else {
            $state = new MoviePersonCreditStateEntity($movie);
            $this->entityManager->persist($state);
        }
        $existing = $this->entityManager->getRepository(MoviePersonCreditEntity::class)->findBy([
            'movie' => $movie,
            'category' => 'crew',
            'role' => 'director',
        ]);
        $links = [];
        foreach ($existing as $link) {
            $links[$link->getPerson()->getId()->toString()] = $link;
        }
        $accepted = [];
        foreach ($distinct as $tmdbId => $credit) {
            // Read after the identity locks, refreshing any entity an earlier batch managed.
            $identifier = $this->entityManager->createQueryBuilder()
                ->select('identifier', 'person')
                ->from(PersonExternalIdEntity::class, 'identifier')
                ->innerJoin('identifier.person', 'person')
                ->where('identifier.namespace = :namespace')
                ->andWhere('identifier.externalId = :externalId')
                ->setParameter('namespace', 'tmdb_person')
                ->setParameter('externalId', (string) $tmdbId)
                ->getQuery()
                ->setHint(Query::HINT_REFRESH, true)
                ->getOneOrNullResult();
            if ($identifier === null) {
                $person = new PersonEntity($credit->name, $credit->profileUrl);
                $this->entityManager->persist($person);
                $this->entityManager->persist(new PersonExternalIdEntity($person, 'tmdb_person', (string) $tmdbId, 'tmdb'));
            } else {
                $person = $identifier->getPerson();
                $person->updateImportedIdentity($credit->name, $credit->profileUrl);
            }
            $personId = $person->getId()->toString();
            $accepted[$personId] = true;
            if (!isset($links[$personId])) {
                $links[$personId] = MoviePersonCreditEntity::director($movie, $person);
                $this->entityManager->persist($links[$personId]);
            }
        }
        foreach ($links as $personId => $link) {
            if (!isset($accepted[$personId])) {
                $this->entityManager->remove($link);
            }
        }
        $state->initializeDirectors();
        $this->entityManager->flush();

        return true;
    }

    private function visibleQuery(LibraryReadScope $scope): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('director')
            ->from(PersonEntity::class, 'director')
            ->innerJoin(PersonExternalIdEntity::class, 'identifier', 'WITH', 'identifier.person = director AND identifier.namespace = :tmdbNamespace')
            ->innerJoin(MoviePersonCreditEntity::class, 'credit', 'WITH', 'credit.person = director AND credit.category = :crewCategory AND credit.role = :directorRole')
            ->innerJoin('credit.movie', 'visible')
            ->setParameter('tmdbNamespace', 'tmdb_person')
            ->setParameter('crewCategory', 'crew')
            ->setParameter('directorRole', 'director');
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

    /** @param array{0: PersonEntity, tmdbId: string, movieCount: int|string} $row */
    private static function readView(array $row): DirectorReadView
    {
        $director = $row[0];

        return new DirectorReadView(
            publicId: $director->getPublicId(),
            name: $director->getName(),
            tmdbId: (int) $row['tmdbId'],
            profileUrl: $director->getProfileUrl(),
            movieCount: (int) $row['movieCount'],
        );
    }
}
