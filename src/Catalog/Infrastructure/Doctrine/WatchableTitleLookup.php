<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine;

use App\Catalog\Application\Dto\WatchableTarget;
use App\Catalog\Application\Dto\WatchableTargets;
use App\Catalog\Application\Dto\WatchableVideo;
use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Catalog\Application\Port\WatchableTitlePortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NoActiveTransaction;

final readonly class WatchableTitleLookup implements WatchableTitlePortInterface
{
    public function __construct(
        private Connection $connection,
        private LibraryReadScopeProviderInterface $scopes,
        private VideoAvailabilityPortInterface $availability,
    ) {}

    public function forProgress(Uuid $memberId, PublicId $videoId, string $kind, PublicId $titleId): ?WatchableVideo
    {
        if (!in_array($kind, ['episode', 'movie', 'extra'], true)) {
            return null;
        }
        $parent = $this->lockedParent($memberId, $kind, $titleId);
        if ($parent === null) {
            return null;
        }
        $links = $this->links($parent, $videoId);
        $available = $this->availableVideos($links, $parent['library_id']);
        $selected = array_filter($links, static fn (array $link): bool => $link['video_public_id'] === $videoId->toString() && isset($available[$link['video_id']]))
            |> array_values(...);
        if ($selected === [] || !array_any($selected, static fn (array $link): bool => $link['target_id'] === $parent['target_id'])) {
            return null;
        }
        $targets = $kind === 'extra' ? [] : $this->targets($selected, $kind);
        $duration = $selected[0]['duration'];

        return new WatchableVideo(Uuid::fromString($selected[0]['video_id']), $duration === null ? null : (float) $duration, $targets);
    }

    public function forMark(Uuid $memberId, string $kind, PublicId $titleId): ?WatchableTargets
    {
        if (!in_array($kind, ['episode', 'season', 'series', 'movie'], true)) {
            return null;
        }
        $parent = $this->lockedParent($memberId, $kind, $titleId);
        if ($parent === null) {
            return null;
        }
        $links = $this->links($parent, selectedOnly: true);
        $available = $this->availableVideos($links, $parent['library_id']);
        $ownedLinks = array_filter($links, static fn (array $link): bool => isset($available[$link['video_id']]))
            |> array_values(...);
        if ($ownedLinks === []) {
            return null;
        }
        $targetKind = $kind === 'movie' ? 'movie' : 'episode';
        $targets = $this->targets($ownedLinks, $targetKind);
        $ownedIds = array_map(static fn (WatchableTarget $target): string => $target->id->toString(), $targets);
        $owned = array_fill_keys($ownedIds, true);
        $videos = [];
        foreach ($links as $link) {
            if (isset($owned[$link['target_id']])) {
                $videos[$link['video_id']] = Uuid::fromString($link['video_id']);
            }
        }

        return new WatchableTargets($targets, array_values($videos));
    }

    /** @return array{parent_id: string, library_id: string, target_id: string, kind: string, parent_kind?: string}|null */
    private function lockedParent(Uuid $memberId, string $kind, PublicId $titleId): ?array
    {
        if (!$this->connection->isTransactionActive()) {
            throw NoActiveTransaction::new();
        }
        $sql = match ($kind) {
            'movie' => 'SELECT id AS parent_id, library_id, id AS target_id FROM movies WHERE public_id = :title',
            'series' => 'SELECT id AS parent_id, library_id, id AS target_id FROM tv_series WHERE public_id = :title',
            'season' => 'SELECT series.id AS parent_id, series.library_id, season.id AS target_id FROM tv_seasons season JOIN tv_series series ON series.id = season.series_id WHERE season.public_id = :title',
            'episode' => 'SELECT series.id AS parent_id, series.library_id, episode.id AS target_id FROM tv_episodes episode JOIN tv_series series ON series.id = episode.series_id WHERE episode.public_id = :title AND episode.retired = FALSE',
            'extra' => <<<'SQL'
                SELECT placement.id AS target_id, placement.library_id,
                  COALESCE(placement.movie_id, placement.series_id) AS parent_id,
                  CASE WHEN placement.movie_id IS NOT NULL THEN 'movie' ELSE 'series' END AS parent_kind
                FROM video_placements placement
                LEFT JOIN movies movie ON movie.id = placement.movie_id
                LEFT JOIN tv_series series ON series.id = placement.series_id
                WHERE placement.public_id = :title AND placement.extra_kind IS NOT NULL
                  AND placement.library_id = COALESCE(movie.library_id, series.library_id)
                SQL,
            default => null,
        };
        if ($sql === null) {
            return null;
        }
        $parameters = ['title' => $titleId->toString()];
        $before = $this->connection->fetchAssociative($sql, $parameters);
        if ($before === false) {
            return null;
        }
        if (!$this->scopes->forUser($memberId)->allows(Uuid::fromString($before['library_id']))) {
            return null;
        }
        $parentKind = $kind === 'extra' ? $before['parent_kind'] : $kind;
        $table = $parentKind === 'movie' ? 'movies' : 'tv_series';
        // This is the same parent row lock held by placement and watched-mark moves.
        if ($this->connection->fetchOne('SELECT id FROM ' . $table . ' WHERE id = :id FOR UPDATE', ['id' => $before['parent_id']]) === false) {
            return null;
        }
        $after = $this->connection->fetchAssociative($sql, $parameters);
        if ($after === false || $after !== $before) {
            return null;
        }
        $scope = $this->scopes->forUser($memberId);
        if (!$scope->allows(Uuid::fromString($after['library_id']))) {
            return null;
        }

        return $after + ['kind' => $kind];
    }

    /**
     * @param array{parent_id: string, library_id: string, target_id: string, kind: string, parent_kind?: string} $parent
     * @return list<array{target_id: string, season_id: string|null, video_id: string, video_public_id: string, duration: int|string|null}>
     */
    private function links(array $parent, ?PublicId $videoId = null, bool $selectedOnly = false): array
    {
        $parameters = ['parent' => $parent['parent_id'], 'library' => $parent['library_id']];
        $filter = '';
        if ($videoId !== null) {
            $filter = ' AND video.public_id = :video';
            $parameters['video'] = $videoId->toString();
        }
        if ($parent['kind'] === 'extra') {
            $parameters['placement'] = $parent['target_id'];
            return $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT placement.id AS target_id, NULL AS season_id, video.id AS video_id, video.public_id AS video_public_id, video.duration
                FROM video_placements placement JOIN videos video ON video.id = placement.video_id
                WHERE placement.id = :placement AND placement.library_id = :library AND placement.extra_kind IS NOT NULL
                  AND (placement.movie_id = :parent OR placement.series_id = :parent)
                SQL . $filter, $parameters);
        }
        if ($parent['kind'] === 'movie') {
            return $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT placement.movie_id AS target_id, NULL AS season_id, video.id AS video_id, video.public_id AS video_public_id, video.duration
                FROM video_placements placement JOIN videos video ON video.id = placement.video_id
                WHERE placement.movie_id = :parent AND placement.library_id = :library AND placement.extra_kind IS NULL
                SQL . $filter . ' ORDER BY placement.sort_order, placement.id', $parameters);
        }

        if ($selectedOnly && in_array($parent['kind'], ['episode', 'season'], true)) {
            $filter .= $parent['kind'] === 'episode' ? ' AND episode.id = :target' : ' AND episode.season_id = :target';
            $parameters['target'] = $parent['target_id'];
        }

        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT episode.id AS target_id, episode.season_id, video.id AS video_id, video.public_id AS video_public_id, video.duration
            FROM video_placements placement
            JOIN videos video ON video.id = placement.video_id
            JOIN video_placement_episode link ON link.placement_id = placement.id
            JOIN tv_episodes episode ON episode.id = link.episode_id AND episode.series_id = :parent AND episode.retired = FALSE
            WHERE placement.series_id = :parent AND placement.library_id = :library AND placement.extra_kind IS NULL
            SQL . $filter . ' ORDER BY placement.sort_order, placement.id, link.sort_order, episode.id', $parameters);
    }

    /**
     * @param list<array{video_id: string, target_id: string, season_id: string|null, video_public_id: string, duration: int|string|null}> $links
     * @return array<string, true>
     */
    private function availableVideos(array $links, string $libraryId): array
    {
        $videos = [];
        foreach ($links as $link) {
            $videos[$link['video_id']] = Uuid::fromString($link['video_id']);
        }
        $library = Uuid::fromString($libraryId);
        $scope = LibraryReadScope::restricted([$library]);
        $paths = $this->availability->availablePaths(array_values($videos), $scope);
        $availableIds = array_keys($paths);

        return array_fill_keys($availableIds, true);
    }

    /**
     * @param list<array{target_id: string}> $links
     * @param 'episode'|'movie' $kind
     * @return list<WatchableTarget>
     */
    private function targets(array $links, string $kind): array
    {
        $targets = [];
        foreach ($links as $link) {
            $targets[$link['target_id']] = new WatchableTarget($kind, Uuid::fromString($link['target_id']));
        }

        return array_values($targets);
    }
}
