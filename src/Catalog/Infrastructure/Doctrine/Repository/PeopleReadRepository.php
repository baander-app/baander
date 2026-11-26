<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Port\PeopleReadPortInterface;
use App\Catalog\Application\Port\PersonPortraitAccessPortInterface;
use App\Catalog\Domain\ValueObject\PersonDate;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonArtistLinkEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvEpisodeEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Catalog\Infrastructure\Doctrine\Query\CatalogReadScopeQuery;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PeopleReadRepository implements PeopleReadPortInterface, PersonPortraitAccessPortInterface
{
    private Connection $connection;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->connection = $entityManager->getConnection();
    }

    public function search(SearchOptions $options, LibraryReadScope $scope, ?string $role = null): SearchResult
    {
        [$cte, $parameters, $types] = $this->visibleCredits($scope, $role);
        $parameters += ['query' => $options->getQuery()];
        $filter = "(strpos(lower(p.name), lower(:query)) > 0 OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(p.aliases) alias(name) WHERE strpos(lower(alias.name), lower(:query)) > 0))";
        $from = ' FROM people p JOIN counts ON counts.person_id = p.id LEFT JOIN images portrait ON portrait.id = p.image_id WHERE ' . $filter;
        $total = (int) $this->connection->fetchOne($cte . ' SELECT COUNT(*)' . $from, $parameters, $types);
        $rows = $this->connection->fetchAllAssociative(
            $cte . ' SELECT p.*, portrait.public_id AS portrait_public_id, counts.*' . $from . ' ORDER BY lower(p.name), p.public_id LIMIT :limit OFFSET :offset',
            $parameters + self::page($options),
            $types + self::pageTypes(),
        );

        return SearchResult::create(array_map(self::person(...), $rows), $total);
    }

    public function profile(PublicId $id, LibraryReadScope $scope): ?array
    {
        [$cte, $parameters, $types] = $this->visibleCredits($scope, personPublicId: $id->toString());
        $row = $this->connection->fetchAssociative(
            $cte . <<<'SQL'
                SELECT p.*, portrait.public_id AS portrait_public_id, counts.*
                FROM people p JOIN counts ON counts.person_id = p.id
                LEFT JOIN images portrait ON portrait.id = p.image_id
                WHERE p.public_id = :publicId OR p.id = (SELECT person_id FROM person_id_aliases WHERE public_id = :publicId)
                SQL,
            $parameters + ['publicId' => $id->toString()],
            $types,
        );

        return $row === false ? null : self::person($row);
    }

    public function credits(PublicId $id, SearchOptions $options, LibraryReadScope $scope, ?string $kind = null, ?string $role = null): SearchResult
    {
        [$cte, $parameters, $types] = $this->visibleCredits($scope, role: $role, personPublicId: $id->toString(), workKind: $kind);
        $parameters['query'] = $options->getQuery();
        // Each union branch applies the Person, medium and library filters before grouping.
        $group = ' FROM visible_credits WHERE strpos(lower(title), lower(:query)) > 0 GROUP BY kind, public_id, title';
        $total = (int) $this->connection->fetchOne($cte . ' SELECT COUNT(*) FROM (SELECT kind, public_id' . $group . ') grouped', $parameters, $types);
        $rows = $this->connection->fetchAllAssociative(
            $cte . <<<'SQL'
                 SELECT kind, public_id AS "publicId", title,
                    jsonb_agg(DISTINCT jsonb_build_object(
                        'role', role, 'job', job, 'characterName', character_name,
                        'department', department, 'creditedName', credited_name, 'billingOrder', billing_order
                    )) AS roles
                SQL
                . $group . ' ORDER BY lower(title), kind, public_id LIMIT :limit OFFSET :offset',
            $parameters + self::page($options),
            $types + self::pageTypes(),
        );
        foreach ($rows as &$row) {
            $row['roles'] = json_decode((string) $row['roles'], true, flags: JSON_THROW_ON_ERROR);
            usort($row['roles'], static fn(array $left, array $right): int => [
                $left['billingOrder'] ?? PHP_INT_MAX, $left['role'], $left['job'], $left['characterName'] ?? '', $left['creditedName'] ?? '',
            ] <=> [
                $right['billingOrder'] ?? PHP_INT_MAX, $right['role'], $right['job'], $right['characterName'] ?? '', $right['creditedName'] ?? '',
            ]);
        }

        return SearchResult::create($rows, $total);
    }

    public function workCredits(string $kind, PublicId $id, SearchOptions $options, LibraryReadScope $scope): ?SearchResult
    {
        $target = match ($kind) {
            'movie' => $this->movieExists($id, $scope),
            'series' => $this->seriesDetail($id, $scope) !== null,
            'episode' => $this->episodeDetail($id, $scope) !== null,
            default => false,
        };
        if (!$target) {
            return null;
        }
        [$cte, $parameters, $types] = $this->visibleCredits($scope, workKind: $kind, workPublicId: $id->toString());
        $parameters['query'] = $options->getQuery();
        $from = <<<'SQL'
             FROM visible_credits credits JOIN people p ON p.id = credits.person_id
             LEFT JOIN images portrait ON portrait.id = p.image_id
             WHERE strpos(lower(p.name), lower(:query)) > 0
            SQL;
        $total = (int) $this->connection->fetchOne($cte . ' SELECT COUNT(*)' . $from, $parameters, $types);
        $rows = $this->connection->fetchAllAssociative(
            $cte . ' SELECT credits.*, p.public_id AS person_public_id, p.name, p.profile_url, portrait.public_id AS portrait_public_id'
                . $from . ' ORDER BY credits.billing_order NULLS LAST, lower(p.name), p.public_id, credits.credit_id LIMIT :limit OFFSET :offset',
            $parameters + self::page($options),
            $types + self::pageTypes(),
        );
        $items = array_map(static fn(array $row): array => [
            'publicId' => $row['person_public_id'],
            'personPublicId' => $row['person_public_id'],
            'name' => $row['name'],
            'profileUrl' => self::portrait($row),
            'role' => [
                'role' => $row['role'],
                'job' => $row['job'],
                'characterName' => $row['character_name'],
                'department' => $row['department'],
                'creditedName' => $row['credited_name'],
                'billingOrder' => $row['billing_order'] === null ? null : (int) $row['billing_order'],
            ],
        ], $rows);

        return SearchResult::create($items, $total);
    }

    public function artistProfile(PublicId $artist, LibraryReadScope $scope): ?array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p.publicId')
            ->from(ArtistEntity::class, 's')
            ->innerJoin(PersonArtistLinkEntity::class, 'links', 'WITH', 'links.artist = s.id')
            ->innerJoin('links.person', 'p')
            ->where('s.publicId = :publicId')
            ->andWhere('(s.mbid IS NULL AND links.artistMbid IS NULL) OR LOWER(s.mbid) = links.artistMbid')
            ->andWhere("s.type IS NULL OR LOWER(s.type) = 'person'")
            ->setParameter('publicId', $artist);
        CatalogReadScopeQuery::apply($query, $scope, 'artist', 's');
        $row = $query->getQuery()->getOneOrNullResult();
        return $row === null ? null : $this->profile(PublicId::fromString((string) $row['publicId']), $scope);
    }

    public function series(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        $query = $this->seriesQuery($scope);
        $query->andWhere('LOCATE(:query, LOWER(s.name)) > 0')->setParameter('query', self::searchText($options));
        $total = (int) (clone $query)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $rows = $query->select('s.publicId', 's.name', 's.overview', 's.posterUrl')
            ->orderBy('s.name')->addOrderBy('s.publicId')
            ->setMaxResults($options->getLimit())->setFirstResult($options->getOffset())
            ->getQuery()->getArrayResult();

        return SearchResult::create(array_map(self::transportRow(...), $rows), $total);
    }

    public function seriesDetail(PublicId $id, LibraryReadScope $scope): ?array
    {
        $row = $this->seriesQuery($scope)->select('s.publicId', 's.name', 's.overview', 's.posterUrl')
            ->andWhere('s.publicId = :publicId')->setParameter('publicId', $id)
            ->getQuery()->getOneOrNullResult();

        return $row === null ? null : self::transportRow($row);
    }

    public function episodes(PublicId $series, SearchOptions $options, LibraryReadScope $scope): ?SearchResult
    {
        if ($this->seriesDetail($series, $scope) === null) {
            return null;
        }
        $query = $this->episodeQuery($scope)->andWhere('s.publicId = :publicId')->setParameter('publicId', $series)
            ->andWhere('LOCATE(:query, LOWER(episode.name)) > 0')->setParameter('query', self::searchText($options));
        $total = (int) (clone $query)->select('COUNT(episode.id)')->getQuery()->getSingleScalarResult();
        $rows = $query->select(self::episodeSelect())
            ->orderBy('season.seasonNumber')->addOrderBy('episode.episodeNumber')->addOrderBy('episode.publicId')
            ->setMaxResults($options->getLimit())->setFirstResult($options->getOffset())
            ->getQuery()->getArrayResult();

        return SearchResult::create(array_map(self::transportRow(...), $rows), $total);
    }

    public function episodeDetail(PublicId $id, LibraryReadScope $scope): ?array
    {
        $row = $this->episodeQuery($scope)->select(self::episodeSelect())
            ->andWhere('episode.publicId = :publicId')->setParameter('publicId', $id)
            ->getQuery()->getOneOrNullResult();

        return $row === null ? null : self::transportRow($row);
    }

    private function seriesQuery(LibraryReadScope $scope): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->from(TvSeriesEntity::class, 's');
        CatalogReadScopeQuery::apply($query, $scope, 'movie', 's');

        return $query;
    }

    private function episodeQuery(LibraryReadScope $scope): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->from(TvEpisodeEntity::class, 'episode')
            ->innerJoin('episode.season', 'season')->innerJoin('season.series', 's');
        CatalogReadScopeQuery::apply($query, $scope, 'movie', 's');

        return $query;
    }

    public function canReadPortrait(PublicId $imagePublicId, LibraryReadScope $scope): bool
    {
        [$cte, $parameters, $types] = $this->visibleCredits($scope, portraitPublicId: $imagePublicId->toString());

        return $this->connection->fetchOne(
            $cte . ' SELECT 1 FROM people p JOIN images image ON image.id = p.image_id JOIN counts ON counts.person_id = p.id WHERE image.public_id = :publicId LIMIT 1',
            $parameters + ['publicId' => $imagePublicId->toString()],
            $types,
        ) !== false;
    }

    private function movieExists(PublicId $id, LibraryReadScope $scope): bool
    {
        $query = $this->entityManager->createQueryBuilder()->select('s.id')->from(MovieEntity::class, 's')
            ->where('s.publicId = :publicId')->setParameter('publicId', $id);
        CatalogReadScopeQuery::apply($query, $scope, 'movie', 's');

        return $query->getQuery()->getOneOrNullResult() !== null;
    }

    /** @return array{string, array<string, mixed>, array<string, mixed>} */
    private function visibleCredits(
        LibraryReadScope $scope,
        ?string $role = null,
        ?string $personPublicId = null,
        ?string $workKind = null,
        ?string $workPublicId = null,
        ?string $portraitPublicId = null,
    ): array
    {
        // DQL has no UNION. Each SQL branch applies the same native Catalog scope
        // before the database groups cross-media work identities and paginates.
        $branches = [];
        $parameters = [];
        $types = [];
        foreach (['movie', 'series', 'episode', 'song', 'album'] as $kind) {
            if ($workKind !== null && $kind !== $workKind) {
                continue;
            }
            $access = CatalogReadScopeQuery::native($scope, $kind === 'song' ? 'song' : 'movie', SearchOptions::create(''));
            $parameters += $access['parameters'];
            $types += $access['types'];
            $owner = $kind === 'song' || $kind === 'album' ? 'links.person_id' : 'credits.person_id';
            $targetAlias = $kind === 'episode' ? 'episode' : 's';
            $filter = '';
            if ($personPublicId !== null) {
                $filter .= " AND {$owner} IN (SELECT id FROM people WHERE public_id = :filter_person UNION ALL SELECT person_id FROM person_id_aliases WHERE public_id = :filter_person)";
                $parameters['filter_person'] = $personPublicId;
            }
            if ($workPublicId !== null) {
                $filter .= " AND {$targetAlias}.public_id = :filter_work";
                $parameters['filter_work'] = $workPublicId;
            }
            if ($portraitPublicId !== null) {
                $filter .= " AND {$owner} IN (SELECT portrait_person.id FROM people portrait_person JOIN images image ON image.id = portrait_person.image_id WHERE image.public_id = :filter_portrait)";
                $parameters['filter_portrait'] = $portraitPublicId;
            }
            if ($kind === 'song' || $kind === 'album') {
                $table = $kind === 'song' ? 'songs' : 'albums';
                $branches[] = "SELECT links.person_id, '{$kind}'::text AS kind, s.public_id, s.title, credits.id::text AS credit_id,"
                    . " COALESCE(credits.role, 'other') AS role, COALESCE(credits.role, 'other') AS job, NULL::text AS character_name,"
                    . ' NULL::text AS department, credits.credited_name, NULL::integer AS billing_order'
                    . " FROM {$table} s JOIN artist_{$kind} credits ON credits.{$kind}_id = s.id"
                    . ' JOIN artists artist ON artist.id = credits.artist_id JOIN person_artist_links links ON links.artist_id = artist.id'
                    . ' WHERE links.person_id IS NOT NULL AND ' . self::validArtistLink('artist') . ' AND (' . $access['predicate'] . ')' . $filter;
            } else {
                $target = match ($kind) {
                    'movie' => 'movies s',
                    'series' => 'tv_series s',
                    default => 'tv_episodes episode JOIN tv_seasons season ON season.id = episode.season_id JOIN tv_series s ON s.id = season.series_id',
                };
                $targetAlias = $kind === 'episode' ? 'episode' : 's';
                $title = $kind === 'movie' ? 's.title' : $targetAlias . '.name';
                $branches[] = "SELECT credits.person_id, '{$kind}'::text AS kind, {$targetAlias}.public_id, {$title} AS title,"
                    . ' credits.id::text AS credit_id, credits.role, credits.job, credits.character_name, credits.department, credits.credited_name, credits.billing_order'
                    . " FROM {$target} JOIN {$kind}_person_credits credits ON credits.{$kind}_id = {$targetAlias}.id"
                    . ' WHERE (' . $access['predicate'] . ')' . $filter;
            }
        }
        $union = implode(' UNION ALL ', $branches);
        if ($role !== null) {
            $union = 'SELECT * FROM (' . $union . ') role_credits WHERE role = :scope_role';
            $parameters['scope_role'] = $role;
        }
        $cte = <<<SQL
            WITH visible_credits AS ({$union}), counts AS (
                SELECT person_id,
                    COUNT(DISTINCT public_id) FILTER (WHERE kind = 'movie') AS movie_count,
                    COUNT(DISTINCT public_id) FILTER (WHERE kind = 'series') AS series_count,
                    COUNT(DISTINCT public_id) FILTER (WHERE kind = 'episode') AS episode_count,
                    COUNT(DISTINCT public_id) FILTER (WHERE kind = 'song') AS song_count,
                    COUNT(DISTINCT public_id) FILTER (WHERE kind = 'album') AS album_count
                FROM visible_credits GROUP BY person_id
            )
            SQL;

        return [$cte, $parameters, $types];
    }

    private static function validArtistLink(string $alias): string
    {
        return "lower({$alias}.mbid) IS NOT DISTINCT FROM links.artist_mbid AND ({$alias}.type IS NULL OR lower({$alias}.type) = 'person')";
    }

    private static function episodeSelect(): string
    {
        return 'episode.publicId, episode.name, episode.overview, episode.stillUrl,'
            . ' episode.airDate, season.seasonNumber, episode.episodeNumber,'
            . ' s.publicId AS seriesPublicId, s.name AS seriesName';
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function transportRow(array $row): array
    {
        foreach ($row as &$value) {
            if ($value instanceof PublicId) {
                $value = $value->toString();
            } elseif ($value instanceof DateTimeImmutable) {
                $value = $value->format('Y-m-d');
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function person(array $row): array
    {
        $birth = PersonDate::fromStorage($row['birth_date'], $row['birth_precision'] === null ? null : (int) $row['birth_precision']);
        $death = PersonDate::fromStorage($row['death_date'], $row['death_precision'] === null ? null : (int) $row['death_precision']);
        $deceased = (bool) $row['is_deceased'];
        $end = $deceased ? $death : PersonDate::parse((new DateTimeImmutable('today'))->format('Y-m-d'));
        return [
            'publicId' => $row['public_id'],
            'name' => $row['name'],
            'profileUrl' => self::portrait($row),
            'biography' => $row['biography'],
            'aliases' => json_decode((string) $row['aliases'], true, flags: JSON_THROW_ON_ERROR),
            'birthDate' => $birth?->display(),
            'deathDate' => $death?->display(),
            'birthplace' => $row['birthplace'],
            'sex' => $row['sex'],
            'gender' => $row['gender'],
            'isDeceased' => $deceased,
            'age' => $end === null ? null : $birth?->ageAt($end),
            'movieCount' => (int) $row['movie_count'],
            'seriesCount' => (int) $row['series_count'],
            'episodeCount' => (int) $row['episode_count'],
            'songCount' => (int) $row['song_count'],
            'albumCount' => (int) $row['album_count'],
        ];
    }

    /** @param array<string, mixed> $row */
    private static function portrait(array $row): ?string
    {
        return $row['portrait_public_id'] === null ? $row['profile_url'] : '/api/images/' . $row['portrait_public_id'] . '/file';
    }

    private static function searchText(SearchOptions $options): string
    {
        return mb_strtolower($options->getQuery());
    }

    /** @return array{limit: int, offset: int} */
    private static function page(SearchOptions $options): array
    {
        return ['limit' => $options->getLimit(), 'offset' => $options->getOffset()];
    }

    /** @return array<string, ParameterType> */
    private static function pageTypes(): array
    {
        return ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER];
    }
}
