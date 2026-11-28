<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\VideoMatch;

use App\Metadata\Application\DTO\Video\VideoKind;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use LogicException;

final readonly class VideoMatchRefreshReview
{
    public function __construct(private Connection $connection) {}

    public function lockForRefresh(VideoKind $kind, Uuid $titleId, DateTimeImmutable $requestedAt): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('A video refresh must hold its match state lock inside a transaction.');
        }
        $column = $kind === VideoKind::Series ? 'series_id' : 'movie_id';
        $state = $this->connection->fetchAssociative(
            "SELECT status, checked_at FROM video_match_states WHERE {$column} = :title FOR UPDATE",
            ['title' => $titleId],
            ['title' => 'uuid'],
        );
        if ($state === false) {
            return true;
        }
        $checkedAt = $state['checked_at'];

        return !in_array($state['status'], ['requested', 'awaiting_provider'], true)
            && ($checkedAt === null || new DateTimeImmutable((string) $checkedAt) < $requestedAt);
    }

    public function recordMissingOrdering(Uuid $seriesId, DateTimeImmutable $checkedAt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO video_match_states (id, series_id, status, source, due_at, reason, checked_at)
                VALUES (:id, :series, 'needs_review', 'ingest', :checked, 'ordering_unavailable', :checked)
                ON CONFLICT (series_id) DO UPDATE
                    SET status = 'needs_review',
                        reason = CASE WHEN video_match_states.status = 'matched' THEN EXCLUDED.reason ELSE video_match_states.reason END,
                        checked_at = EXCLUDED.checked_at
                WHERE video_match_states.status IN ('matched', 'needs_review')
                SQL,
            ['id' => Uuid::v7(), 'series' => $seriesId, 'checked' => $checkedAt],
            ['id' => 'uuid', 'series' => 'uuid', 'checked' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function recordRefreshed(VideoKind $kind, Uuid $titleId, DateTimeImmutable $checkedAt): void
    {
        $column = $kind === VideoKind::Series ? 'series_id' : 'movie_id';
        $this->connection->executeStatement(
            <<<SQL
                INSERT INTO video_match_states (id, {$column}, status, source, due_at, checked_at)
                VALUES (:id, :title, 'matched', 'ingest', :checked, :checked)
                ON CONFLICT ({$column}) DO UPDATE
                    SET status = CASE WHEN video_match_states.reason = 'ordering_unavailable' THEN EXCLUDED.status ELSE video_match_states.status END,
                        reason = CASE WHEN video_match_states.reason = 'ordering_unavailable' THEN NULL ELSE video_match_states.reason END,
                        checked_at = EXCLUDED.checked_at
                WHERE video_match_states.status IN ('matched', 'needs_review')
                SQL,
            ['id' => Uuid::v7(), 'title' => $titleId, 'checked' => $checkedAt],
            ['id' => 'uuid', 'title' => 'uuid', 'checked' => Types::DATETIME_IMMUTABLE],
        );
    }
}
