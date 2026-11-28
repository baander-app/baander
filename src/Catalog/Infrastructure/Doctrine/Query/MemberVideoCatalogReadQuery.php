<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use App\Catalog\Application\Port\MemberVideoCatalogReadInterface;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * @phpstan-import-type WorkKind from VideoWorkAvailability
 * @phpstan-import-type SeriesRow from MemberVideoCatalogReadInterface
 * @phpstan-import-type EpisodeRow from MemberVideoCatalogReadInterface
 * @phpstan-import-type MovieRow from MemberVideoCatalogReadInterface
 * @phpstan-import-type SeasonRow from MemberVideoCatalogReadInterface
 * @phpstan-type WorkRow array{id:string, library_id:string, matches?:bool, season_id?:?string, ...}
 * @phpstan-type RawSeries array{id:string, library_id:string, public_id:string, library_public_id:string, name:string, overview:?string, poster_url:?string, matched_provider:?string}
 * @phpstan-type RawEpisode array{id:string, library_id:string, public_id:string, name:string, overview:?string, still_url:?string, air_date:?string, season_public_id:?string, season_number:?int, episode_number:?int, absolute_number:?int, series_public_id:string, series_name:string, library_public_id:string, matched_provider:?string, matches:bool}
 * @phpstan-type RawMovie array{id:string, library_id:string, library_public_id:string}
 */
final readonly class MemberVideoCatalogReadQuery implements MemberVideoCatalogReadInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private Connection $connection,
        private VideoWorkAvailability $availability,
        private VideoViewingProjection $viewing,
        private MovieRepositoryInterface $movieRepository,
    ) {}

    public function series(SearchOptions $options, LibraryReadScope $scope, ?PublicId $libraryId = null): SearchResult
    {
        $search = $options->getQuery() |> mb_strtolower(...);
        $query = $this->seriesQuery($scope)
            ->andWhere('strpos(lower(s.name), :query) > 0')
            ->setParameter('query', $search)
            ->orderBy('s.name')->addOrderBy('s.public_id');
        if ($libraryId !== null) {
            $query->andWhere('library.public_id = :library')->setParameter('library', $libraryId->toString());
        }

        $page = $this->page($query, 'series', $options);

        return SearchResult::create(array_map(self::seriesRow(...), $page['items']), $page['total']);
    }

    public function seriesDetail(PublicId $id, LibraryReadScope $scope, ?Uuid $memberId): ?array
    {
        $series = $this->findSeries($id, $scope);
        if ($series === null) {
            return null;
        }

        [$seasons, $unseasonedCount] = $this->seasonCounts($series['id'], $scope, $memberId);
        $files = $this->availability->files('series', [$series['id'] => $series['library_id']], extrasOnly: true);
        if ($seasons === [] && $unseasonedCount === 0 && $files === []) {
            return null;
        }
        $state = $this->viewing->state($memberId, [], [], $files);

        return self::seriesRow($series) + [
            'seasons' => $seasons,
            'unseasonedCount' => $unseasonedCount,
            'extras' => $this->viewing->extras($files[$series['id']] ?? [], $state),
        ];
    }

    public function episodes(
        PublicId $series,
        SearchOptions $options,
        LibraryReadScope $scope,
        ?Uuid $memberId,
        ?PublicId $seasonId = null,
        bool $unseasoned = false,
    ): ?SearchResult {
        $owner = $this->findSeries($series, $scope);
        if ($owner === null) {
            return null;
        }
        $search = $options->getQuery() |> mb_strtolower(...);
        $query = $this->episodeQuery($scope)
            ->andWhere('s.id = :series')->setParameter('series', $owner['id'])
            ->addSelect('(strpos(lower(e.name), :query) > 0) AS matches')
            ->setParameter('query', $search)
            ->orderBy('CASE WHEN season.season_number = 0 THEN 1 ELSE 0 END')
            ->addOrderBy('season.season_number')
            ->addOrderBy('e.episode_number')
            ->addOrderBy('e.absolute_number')
            ->addOrderBy('e.air_date')
            ->addOrderBy('e.public_id');
        if ($seasonId !== null) {
            $query->andWhere('season.public_id = :season')->setParameter('season', $seasonId->toString());
        } elseif ($unseasoned) {
            $query->andWhere('season.id IS NULL');
        }

        ['items' => $items, 'total' => $total, 'anyVisible' => $hasAvailableEpisodes] = $this->page($query, 'episode', $options);
        if (!$hasAvailableEpisodes && ($seasonId !== null || $unseasoned
            || $this->availability->files('series', [$owner['id'] => $owner['library_id']], extrasOnly: true) === [])) {
            return null;
        }

        return SearchResult::create($this->episodeRows($items, $memberId), $total);
    }

    public function episodeDetail(PublicId $id, LibraryReadScope $scope, ?Uuid $memberId): ?array
    {
        /** @var RawEpisode|false $row */
        $row = $this->episodeQuery($scope)
            ->andWhere('e.public_id = :id')->setParameter('id', $id->toString())
            ->addSelect('TRUE AS matches')
            ->fetchAssociative();

        return $row === false ? null : ($this->episodeRows([$row], $memberId)[0] ?? null);
    }

    public function movies(SearchOptions $options, LibraryReadScope $scope, ?Uuid $memberId): SearchResult
    {
        $query = $this->movieQuery($scope, $options);
        if ($options->hasQuery()) {
            $query->andWhere('s.title &@~ :query')
                ->setParameter('query', $options->getQuery())
                ->addSelect('pgroonga_score(s.tableoid, s.ctid) AS score')
                ->orderBy('score', 'DESC');
        } else {
            $query->orderBy('s.title');
        }
        $query->addOrderBy('s.id');
        $page = $this->page($query, 'movie', $options);

        return SearchResult::create(
            $this->movieRows($page['items'], $scope, $memberId),
            $page['total'],
        );
    }

    public function movieDetail(PublicId $id, LibraryReadScope $scope, ?Uuid $memberId): ?array
    {
        /** @var RawMovie|false $row */
        $row = $this->movieQuery($scope, SearchOptions::create(''))
            ->andWhere('s.public_id = :id')->setParameter('id', $id->toString())
            ->fetchAssociative();

        return $row === false ? null : ($this->movieRows([$row], $scope, $memberId)[0] ?? null);
    }

    private function seriesQuery(LibraryReadScope $scope): QueryBuilder
    {
        return $this->scopedQuery($scope, SearchOptions::create(''))
            ->select('s.id', 's.library_id', 's.public_id', 's.name', 's.overview', 's.poster_url', 's.matched_provider', 'library.public_id AS library_public_id')
            ->from('tv_series', 's')
            ->innerJoin('s', 'libraries', 'library', 'library.id = s.library_id');
    }

    private function episodeQuery(LibraryReadScope $scope): QueryBuilder
    {
        return $this->scopedQuery($scope, SearchOptions::create(''))
            ->select(
                'e.id', 'e.public_id', 'e.name', 'e.overview', 'e.still_url', 'e.air_date',
                'e.episode_number', 'e.absolute_number',
                'season.public_id AS season_public_id', 'season.season_number',
                's.public_id AS series_public_id', 's.name AS series_name', 's.library_id', 's.matched_provider',
                'library.public_id AS library_public_id',
            )
            ->from('tv_episodes', 'e')
            ->innerJoin('e', 'tv_series', 's', 's.id = e.series_id')
            ->innerJoin('s', 'libraries', 'library', 'library.id = s.library_id')
            ->leftJoin('e', 'tv_seasons', 'season', 'season.id = e.season_id AND season.series_id = e.series_id')
            ->andWhere('e.retired = FALSE');
    }

    private function movieQuery(LibraryReadScope $scope, SearchOptions $options): QueryBuilder
    {
        return $this->scopedQuery($scope, $options)
            ->select('s.id', 's.library_id', 'library.public_id AS library_public_id')
            ->from('movies', 's')
            ->innerJoin('s', 'libraries', 'library', 'library.id = s.library_id');
    }

    private function scopedQuery(LibraryReadScope $scope, SearchOptions $options): QueryBuilder
    {
        $scopeSql = CatalogReadScopeQuery::native($scope, 'movie', $options);

        return $this->connection->createQueryBuilder()
            ->where($scopeSql['predicate'])
            ->setParameters($scopeSql['parameters'], $scopeSql['types']);
    }

    /** @return RawSeries|null */
    private function findSeries(PublicId $id, LibraryReadScope $scope): ?array
    {
        /** @var RawSeries|false $row */
        $row = $this->seriesQuery($scope)->andWhere('s.public_id = :id')->setParameter('id', $id->toString())->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @param WorkKind $kind
     * @return array{items:list<mixed>,total:int,anyVisible:bool}
     */
    private function page(QueryBuilder $query, string $kind, SearchOptions $options): array
    {
        $items = [];
        $total = 0;
        $anyVisible = false;
        $offset = $options->getOffset();
        $limit = $options->getLimit();
        foreach ($this->batches($query) as $rows) {
            $visible = $this->visibleIds($kind, $rows);
            $anyVisible = $anyVisible || $visible !== [];
            foreach ($rows as $row) {
                if (!isset($visible[$row['id']]) || !($row['matches'] ?? true)) {
                    continue;
                }
                if ($total >= $offset && count($items) < $limit) {
                    $items[] = $row;
                }
                ++$total;
            }
        }

        return ['items' => $items, 'total' => $total, 'anyVisible' => $anyVisible];
    }

    /** @return iterable<list<WorkRow>> */
    private function batches(QueryBuilder $query): iterable
    {
        /** @var iterable<list<WorkRow>> $batches */
        $batches = AvailabilityReadCursor::batches(
            $this->connection,
            $query->getSQL(),
            $query->getParameters(),
            $query->getParameterTypes(),
            self::BATCH_SIZE,
        );

        yield from $batches;
    }

    /**
     * @param WorkKind $kind
     * @param list<WorkRow> $rows
     * @return array<string,true>
     */
    private function visibleIds(string $kind, array $rows): array
    {
        $works = array_map(static fn (array $row): array => [
            'kind' => $kind,
            'id' => $row['id'],
            'libraryId' => $row['library_id'],
        ], $rows);

        return array_fill_keys(array_column($this->availability->visibleWorks($works), 'id'), true);
    }

    /** @return array{list<SeasonRow>,int} */
    private function seasonCounts(string $series, LibraryReadScope $scope, ?Uuid $memberId): array
    {
        $episodes = $this->episodeQuery($scope)
            ->select('e.id', 'e.season_id', 's.library_id')
            ->andWhere('s.id = :series')->setParameter('series', $series)
            ->orderBy('e.id');

        $counts = [];
        foreach ($this->batches($episodes) as $rows) {
            $available = $this->visibleIds('episode', $rows);
            $state = $this->viewing->state($memberId, array_keys($available), [], []);
            $watched = self::uuidSet($state->watchedEpisodeIds);
            foreach ($rows as $row) {
                $season = $row['season_id'] ?? '';
                $counts[$season] ??= ['totalCount' => 0, 'ownedCount' => 0, 'watchedCount' => 0];
                ++$counts[$season]['totalCount'];
                if (isset($available[$row['id']])) {
                    ++$counts[$season]['ownedCount'];
                    if (isset($watched[$row['id']])) {
                        ++$counts[$season]['watchedCount'];
                    }
                }
            }
        }

        $seasons = [];
        $seasonIds = array_keys(array_filter(
            $counts,
            static fn (array $count, string $id): bool => $id !== '' && $count['ownedCount'] > 0,
            ARRAY_FILTER_USE_BOTH,
        ));
        foreach (array_chunk($seasonIds, self::BATCH_SIZE) as $ids) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, public_id, season_number, name FROM tv_seasons WHERE series_id = :series AND id IN (:ids)',
                ['series' => $series, 'ids' => $ids],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                $seasons[] = [
                    'publicId' => $row['public_id'],
                    'number' => $row['season_number'],
                    'name' => $row['name'],
                ] + $counts[$row['id']];
            }
        }
        usort($seasons, static fn (array $a, array $b): int => [$a['number'] === 0, $a['number']] <=> [$b['number'] === 0, $b['number']]);

        return [$seasons, $counts['']['ownedCount'] ?? 0];
    }

    /**
     * @param list<RawEpisode> $rows
     * @return list<EpisodeRow>
     */
    private function episodeRows(array $rows, ?Uuid $memberId): array
    {
        $files = $this->availability->files('episode', array_column($rows, 'library_id', 'id'));
        $state = $this->viewing->state($memberId, array_keys($files), [], $files);
        $watched = self::uuidSet($state->watchedEpisodeIds);
        $episodes = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (!isset($files[$id])) {
                continue;
            }
            $episodes[] = [
                'publicId' => $row['public_id'],
                'name' => $row['name'],
                'overview' => $row['overview'],
                'stillUrl' => $row['still_url'],
                'airDate' => $row['air_date'],
                'seasonPublicId' => $row['season_public_id'],
                'seasonNumber' => $row['season_number'],
                'episodeNumber' => $row['episode_number'],
                'absoluteNumber' => $row['absolute_number'],
                'seriesPublicId' => $row['series_public_id'],
                'seriesName' => $row['series_name'],
                'libraryPublicId' => $row['library_public_id'],
                'metadataSource' => $row['matched_provider'],
            ] + $this->viewing->title($files[$id], $state, isset($watched[$id]));
        }

        return $episodes;
    }

    /**
     * @param list<RawMovie> $rows
     * @return list<MovieRow>
     */
    private function movieRows(array $rows, LibraryReadScope $scope, ?Uuid $memberId): array
    {
        $rows = array_column($rows, null, 'id');
        $files = $this->availability->files('movie', array_column($rows, 'library_id', 'id'));
        $ids = array_values(array_intersect(array_keys($rows), array_keys($files)));
        $state = $this->viewing->state($memberId, [], $ids, $files);
        $watched = self::uuidSet($state->watchedMovieIds);
        $result = [];
        foreach ($this->movieRepository->findVisibleByUuids(array_map(Uuid::fromString(...), $ids), $scope) as $movie) {
            $id = $movie->getId()->toString();
            $result[] = [
                'movie' => $movie,
                'libraryPublicId' => $rows[$id]['library_public_id'],
                'metadataSource' => $movie->getMatchedProvider(),
                'extras' => $this->viewing->extras($files[$id], $state),
            ] + $this->viewing->title($files[$id], $state, isset($watched[$id]));
        }

        return $result;
    }

    /**
     * @param RawSeries $row
     * @return SeriesRow
     */
    private static function seriesRow(array $row): array
    {
        return [
            'publicId' => $row['public_id'],
            'libraryPublicId' => $row['library_public_id'],
            'name' => $row['name'],
            'overview' => $row['overview'],
            'posterUrl' => $row['poster_url'],
            'metadataSource' => $row['matched_provider'],
        ];
    }

    /**
     * @param list<Uuid> $ids
     * @return array<string,true>
     */
    private static function uuidSet(array $ids): array
    {
        return array_fill_keys(array_map(static fn (Uuid $id): string => $id->toString(), $ids), true);
    }
}
