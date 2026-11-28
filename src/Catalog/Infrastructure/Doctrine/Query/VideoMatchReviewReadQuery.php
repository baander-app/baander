<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use App\Catalog\Application\Query\VideoMatch\VideoMatchLibraryPreview;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReview;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReviewPage;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReviewReadInterface;
use App\Catalog\Application\Query\VideoMatch\VideoMatchTargetIdentity;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Repository\VideoProviderPauseRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvEpisodeEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEntity;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Type\InstantType;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use InvalidArgumentException;

/** DQL has no UNION for the three review kinds or a library's disjoint title kinds. */
final readonly class VideoMatchReviewReadQuery implements VideoMatchReviewReadInterface
{
    private const string REVIEWS = <<<'SQL'
        WITH reviews AS (
            SELECT 'series' AS kind, series.id, series.public_id, series.name AS title, NULL::text AS path,
                   series.library_id, library.public_id AS library_public_id, library.name AS library_name,
                   state.reason, state.checked_at, state.best_score,
                   'series' AS owner_kind, series.public_id AS owner_public_id
            FROM video_match_states state
            JOIN tv_series series ON series.id = state.series_id
            JOIN libraries library ON library.id = series.library_id
            WHERE state.status = 'needs_review'
            UNION ALL
            SELECT 'movie', movie.id, movie.public_id, movie.title, NULL::text,
                   movie.library_id, library.public_id, library.name,
                   state.reason, state.checked_at, state.best_score, 'movie', movie.public_id
            FROM video_match_states state
            JOIN movies movie ON movie.id = state.movie_id
            JOIN libraries library ON library.id = movie.library_id
            WHERE state.status = 'needs_review'
            UNION ALL
            SELECT 'file', placement.id, placement.public_id,
                   COALESCE(series.name, movie.title, placement.relative_path), placement.relative_path,
                   placement.library_id, library.public_id, library.name,
                   placement.review_reason, placement.review_checked_at, NULL::smallint,
                   CASE WHEN placement.series_id IS NOT NULL THEN 'series' ELSE 'movie' END,
                   COALESCE(series.public_id, movie.public_id)
            FROM video_placements placement
            JOIN libraries library ON library.id = placement.library_id
            LEFT JOIN tv_series series ON series.id = placement.series_id
            LEFT JOIN movies movie ON movie.id = placement.movie_id
            WHERE placement.review_reason IS NOT NULL AND placement.review_checked_at IS NOT NULL
        )
        SELECT * FROM reviews
        WHERE (CAST(:kind AS text) IS NULL OR kind = :kind)
          AND (CAST(:library AS uuid) IS NULL OR library_id = :library)
          AND (CAST(:reason AS text) IS NULL OR reason = :reason)
        SQL;

    private const string TARGETS = <<<'SQL'
        WITH titles AS (
            SELECT 'tv' AS kind, id, public_id, library_id, hand_matched, matched_provider, provider_id, ordering
            FROM tv_series WHERE library_id = :library
            UNION ALL
            SELECT 'movie', id, public_id, library_id, hand_matched, matched_provider, provider_id, NULL::text
            FROM movies WHERE library_id = :library
        )
        SQL;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $manager,
        private CursorCodec $cursorCodec,
        private VideoProviderPauseRepositoryInterface $pauses,
    ) {}

    public function page(?string $cursor, int $limit, ?string $kind = null, ?Uuid $libraryId = null, ?string $reason = null): VideoMatchReviewPage
    {
        self::validateLimit($limit);
        if ($kind !== null && !in_array($kind, ['series', 'movie', 'file'], true)) {
            throw new InvalidInputException('Review kind must be series, movie or file.');
        }
        $binding = json_encode([$kind, $libraryId?->toString(), $reason], JSON_THROW_ON_ERROR)
            |> (static fn (string $filters): string => hash('sha256', $filters));
        $after = $cursor === null ? null : $this->position($cursor, $binding);
        $parameters = ['kind' => $kind, 'library' => $libraryId, 'reason' => $reason, 'rows' => $limit + 1];
        $types = ['library' => 'uuid', 'rows' => ParameterType::INTEGER];
        $predicate = '';
        if ($after !== null) {
            $predicate = ' AND (checked_at, kind, id) < (:checkedAt, :afterKind, :afterId)';
            $parameters += $after;
            $types += ['checkedAt' => Types::DATETIME_IMMUTABLE, 'afterId' => 'uuid'];
        }
        $rows = $this->connection->fetchAllAssociative(
            self::REVIEWS . $predicate . ' ORDER BY checked_at DESC, kind DESC, id DESC LIMIT :rows',
            $parameters,
            $types,
        );
        $selected = array_slice($rows, 0, $limit);
        $hasNextPage = count($rows) > $limit;
        $last = array_last($selected);

        return new VideoMatchReviewPage(
            items: array_map($this->review(...), $selected),
            nextCursor: $hasNextPage && $last !== null ? $this->cursorAfter($last, $binding) : null,
            hasNextPage: $hasNextPage,
            perPage: $limit,
            pausedProviders: $this->pauses->activePauses(),
        );
    }

    public function findTarget(string $kind, PublicId $publicId): ?VideoMatchTargetIdentity
    {
        if (!in_array($kind, ['tv', 'movie'], true)) {
            throw new InvalidInputException('A video title kind must be tv or movie.');
        }
        $entity = $this->manager->createQueryBuilder()
            ->select('title')
            ->from($kind === 'tv' ? TvSeriesEntity::class : MovieEntity::class, 'title')
            ->where('title.publicId = :publicId')
            ->setParameter('publicId', $publicId, 'public_id')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if ($entity instanceof TvSeriesEntity) {
            return VideoMatchTargetIdentity::fromStorage(
                id: $entity->id,
                kind: $kind,
                publicId: $entity->publicId->toString(),
                libraryId: $entity->library->getId(),
                handMatched: $entity->handMatched,
                matchedProvider: $entity->matchedProvider,
                providerId: $entity->providerId,
                ordering: $entity->ordering,
            );
        }
        if ($entity instanceof MovieEntity) {
            return VideoMatchTargetIdentity::fromStorage(
                id: $entity->getId(),
                kind: $kind,
                publicId: $entity->getPublicId()->toString(),
                libraryId: $entity->getLibrary()->getId(),
                handMatched: $entity->isHandMatched(),
                matchedProvider: $entity->getMatchedProvider(),
                providerId: $entity->getProviderId(),
                ordering: null,
            );
        }

        return null;
    }

    public function findPlacement(PublicId $publicId): ?VideoPlacement
    {
        $entity = $this->manager->createQueryBuilder()
            ->select('placement')
            ->from(VideoPlacementEntity::class, 'placement')
            ->where('placement.publicId = :publicId')
            ->setParameter('publicId', $publicId, 'public_id')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof VideoPlacementEntity ? VideoPlacement::reconstitute($entity->state()) : null;
    }

    public function previewLibrary(Uuid $libraryId): VideoMatchLibraryPreview
    {
        $row = $this->connection->fetchAssociative(
            self::TARGETS . <<<'SQL'
                SELECT count(*) AS total_titles, count(*) FILTER (WHERE hand_matched) AS hand_matched_titles,
                       (SELECT count(*) FROM video_placements WHERE library_id = :library AND assigned) AS hand_assigned_files,
                       (SELECT public_id FROM libraries WHERE id = :library) AS library_public_id
                FROM titles
                SQL,
            ['library' => $libraryId],
            ['library' => 'uuid'],
        );
        if ($row === false) {
            throw new \LogicException('A library preview aggregate must return one row.');
        }
        if ($row['library_public_id'] === null) {
            throw new NotFoundException('Library not found.');
        }

        return new VideoMatchLibraryPreview(
            totalTitles: (int) $row['total_titles'],
            handMatchedTitles: (int) $row['hand_matched_titles'],
            handAssignedFiles: (int) $row['hand_assigned_files'],
            libraryPublicId: (string) $row['library_public_id'],
        );
    }

    public function findEpisode(PublicId $publicId): ?TvEpisode
    {
        $entity = $this->manager->createQueryBuilder()
            ->select('episode')
            ->from(TvEpisodeEntity::class, 'episode')
            ->where('episode.publicId = :publicId')
            ->setParameter('publicId', $publicId, 'public_id')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof TvEpisodeEntity ? TvEpisode::reconstitute($entity->state()) : null;
    }

    public function episodeChoices(VideoMatchTargetIdentity $owner, int $page, int $limit): array
    {
        $offset = self::choiceOffset($page, $limit);
        if ($owner->storageKind() !== 'tv') {
            return ['items' => [], 'total' => 0];
        }

        $query = $this->manager->createQueryBuilder()
            ->from(TvEpisodeEntity::class, 'episode')
            ->join('episode.series', 'series')
            ->leftJoin('episode.season', 'season')
            ->where('series.id = :owner AND series.library = :library AND episode.retired = false')
            ->setParameter('owner', $owner->id, 'uuid')
            ->setParameter('library', $owner->libraryId, 'uuid');
        $total = (int) (clone $query)->select('COUNT(episode.id)')->getQuery()->getSingleScalarResult();
        if ($offset >= $total) {
            return ['items' => [], 'total' => $total];
        }

        $rows = $query->select(
            'episode.publicId AS publicId', 'season.seasonNumber AS seasonNumber', 'episode.episodeNumber AS episodeNumber',
            'episode.name AS title', 'episode.absoluteNumber AS absoluteNumber', 'episode.airDate AS airDate',
        )
            ->orderBy('season.seasonNumber', 'ASC')
            ->addOrderBy('episode.episodeNumber', 'ASC')
            ->addOrderBy('episode.absoluteNumber', 'ASC')
            ->addOrderBy('episode.airDate', 'ASC')
            ->addOrderBy('episode.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return [
            'items' => array_map(static fn (array $row): array => [
                'publicId' => (string) $row['publicId'],
                'seasonNumber' => $row['seasonNumber'] === null ? null : (int) $row['seasonNumber'],
                'episodeNumber' => $row['episodeNumber'] === null ? null : (int) $row['episodeNumber'],
                'title' => (string) $row['title'],
                'absoluteNumber' => $row['absoluteNumber'] === null ? null : (int) $row['absoluteNumber'],
                'airDate' => $row['airDate'] instanceof DateTimeImmutable ? $row['airDate']->format('Y-m-d') : null,
            ], $rows),
            'total' => $total,
        ];
    }

    public function placementChoices(VideoMatchTargetIdentity $owner, int $page, int $limit): array
    {
        $offset = self::choiceOffset($page, $limit);
        [$table, $foreignKey] = $owner->storageKind() === 'tv' ? ['tv_series', 'series_id'] : ['movies', 'movie_id'];
        $scope = <<<SQL
            FROM video_placements placement
            JOIN {$table} title ON title.id = placement.{$foreignKey} AND title.library_id = placement.library_id
            WHERE title.id = :owner AND title.library_id = :library
            SQL;
        $parameters = ['owner' => $owner->id, 'library' => $owner->libraryId];
        $types = ['owner' => 'uuid', 'library' => 'uuid'];
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) ' . $scope, $parameters, $types);
        if ($offset >= $total) {
            return ['items' => [], 'total' => $total];
        }

        // Page before aggregating episode links so multi-episode files use one slot.
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                WITH selected AS (
                    SELECT placement.id, placement.public_id, placement.relative_path, placement.assigned, placement.extra_kind, placement.series_id
                    {$scope}
                    ORDER BY placement.relative_path, placement.id LIMIT :limit OFFSET :offset
                )
                SELECT selected.id, selected.public_id, selected.relative_path, selected.assigned, selected.extra_kind,
                       COALESCE(jsonb_agg(episode.public_id ORDER BY link.sort_order, link.id)
                           FILTER (WHERE episode.id IS NOT NULL), '[]'::jsonb) AS episode_public_ids
                FROM selected
                LEFT JOIN video_placement_episode link ON link.placement_id = selected.id
                LEFT JOIN tv_episodes episode ON episode.id = link.episode_id AND episode.series_id = selected.series_id
                GROUP BY selected.id, selected.public_id, selected.relative_path, selected.assigned, selected.extra_kind
                ORDER BY selected.relative_path, selected.id
                SQL,
            $parameters + ['limit' => $limit, 'offset' => $offset],
            $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return ['items' => array_map(self::placementChoice(...), $rows), 'total' => $total];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{publicId: string, path: string, assigned: bool, episodePublicIds: list<string>, extraKind: ?string}
     */
    private static function placementChoice(array $row): array
    {
        /** @var list<string> $episodes */
        $episodes = json_decode((string) $row['episode_public_ids'], true, flags: JSON_THROW_ON_ERROR);

        return [
            'publicId' => (string) $row['public_id'],
            'path' => (string) $row['relative_path'],
            'assigned' => (bool) $row['assigned'],
            'episodePublicIds' => $episodes,
            'extraKind' => $row['extra_kind'] === null ? null : (string) $row['extra_kind'],
        ];
    }

    private static function choiceOffset(int $page, int $limit): int
    {
        self::validateLimit($limit);
        if ($page < 1 || $page - 1 > intdiv(PHP_INT_MAX, $limit)) {
            throw new InvalidInputException('Invalid video editor page.');
        }

        return ($page - 1) * $limit;
    }

    public function targetsByLibrary(Uuid $libraryId, ?VideoMatchTargetIdentity $after = null, int $limit = 100): array
    {
        self::validateLimit($limit);
        if ($after !== null && !$after->libraryId->equals($libraryId)) {
            throw new InvalidInputException('The title cursor belongs to another library.');
        }
        $rows = $this->connection->fetchAllAssociative(
            self::TARGETS . ' SELECT * FROM titles WHERE (CAST(:kind AS text) IS NULL OR (kind, id) > (:kind, :after)) ORDER BY kind, id LIMIT :rows',
            ['library' => $libraryId, 'kind' => $after?->storageKind(), 'after' => $after?->id, 'rows' => $limit],
            ['library' => 'uuid', 'after' => 'uuid', 'rows' => ParameterType::INTEGER],
        );

        return array_map($this->target(...), $rows);
    }

    private static function validateLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidInputException('Review limit must be between 1 and 100.');
        }
    }

    /** @param array<string, mixed> $row */
    private function review(array $row): VideoMatchReview
    {
        return new VideoMatchReview(
            kind: (string) $row['kind'],
            publicId: (string) $row['public_id'],
            title: (string) $row['title'],
            path: $row['path'] === null ? null : (string) $row['path'],
            libraryPublicId: (string) $row['library_public_id'],
            libraryName: (string) $row['library_name'],
            reason: (string) $row['reason'],
            checkedAt: $this->instant($row['checked_at']),
            bestScore: $row['best_score'] === null ? null : (int) $row['best_score'],
            ownerKind: (string) $row['owner_kind'],
            ownerPublicId: (string) $row['owner_public_id'],
        );
    }

    /** @param array<string, mixed> $row */
    private function target(array $row): VideoMatchTargetIdentity
    {
        return VideoMatchTargetIdentity::fromStorage(
            id: Uuid::fromString((string) $row['id']),
            kind: (string) $row['kind'],
            publicId: (string) $row['public_id'],
            libraryId: Uuid::fromString((string) $row['library_id']),
            handMatched: (bool) $row['hand_matched'],
            matchedProvider: $row['matched_provider'] === null ? null : (string) $row['matched_provider'],
            providerId: $row['provider_id'] === null ? null : (string) $row['provider_id'],
            ordering: $row['ordering'] === null ? null : (string) $row['ordering'],
        );
    }

    /** @param array<string, mixed> $row */
    private function cursorAfter(array $row, string $binding): string
    {
        return $this->cursorCodec->encode(Cursor::create(CursorDirection::Next, [
            'binding' => $binding,
            'sort' => $this->instant($row['checked_at'])->format(InstantType::WRITE_FORMAT),
            'kind' => (string) $row['kind'],
            'id' => (string) $row['id'],
        ]));
    }

    /** @return array{checkedAt: DateTimeImmutable, afterKind: string, afterId: Uuid} */
    private function position(string $cursor, string $binding): array
    {
        $decoded = $this->cursorCodec->decode($cursor);
        $values = $decoded?->getValues() ?? [];
        $sort = $values['sort'] ?? null;
        $kind = $values['kind'] ?? null;
        $id = $values['id'] ?? null;
        try {
            $checkedAt = is_string($sort) ? DateTimeImmutable::createFromFormat(InstantType::WRITE_FORMAT, $sort) : false;
        } catch (\ValueError) {
            $checkedAt = false;
        }
        if ($decoded?->getDirection() !== CursorDirection::Next || ($values['binding'] ?? null) !== $binding
            || $checkedAt === false || $checkedAt->format(InstantType::WRITE_FORMAT) !== $sort
            || !is_string($kind) || !in_array($kind, ['series', 'movie', 'file'], true) || !is_string($id)
        ) {
            throw new InvalidInputException('The cursor does not belong to this video review list.');
        }
        try {
            $afterId = Uuid::fromString($id);
        } catch (InvalidArgumentException $error) {
            throw new InvalidInputException('The video review cursor has an invalid identifier.', previous: $error);
        }

        return ['checkedAt' => $checkedAt, 'afterKind' => $kind, 'afterId' => $afterId];
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        return $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE)
            ?? throw new \UnexpectedValueException('A video review has no check time.');
    }
}
