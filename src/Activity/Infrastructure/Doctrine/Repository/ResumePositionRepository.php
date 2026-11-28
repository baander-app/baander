<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Repository;

use App\Activity\Domain\Repository\ResumePositionRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use LogicException;

final readonly class ResumePositionRepository implements ResumePositionRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function record(
        Uuid $userId,
        Uuid $videoId,
        ?float $positionSeconds,
        DateTimeImmutable $reportedAt,
        bool $force,
        int $clockSkewSeconds = 0,
    ): bool {
        $this->requireTransaction();
        if ($positionSeconds !== null && (!is_finite($positionSeconds) || $positionSeconds < 0)) {
            throw new InvalidArgumentException('A resume position must be finite and nonnegative.');
        }
        if ($clockSkewSeconds < 0) {
            throw new InvalidArgumentException('Clock skew tolerance must be nonnegative.');
        }
        // DQL has no conditional upsert: the time and movement fences must inspect the conflicting row atomically.
        return $this->connection->fetchOne(<<<'SQL'
            INSERT INTO resume_positions (id, user_id, video_id, position_seconds, reported_at, last_played_at, protected_through_at)
            SELECT :id, :user, :video, :position, :reported, :reported,
                CASE WHEN CAST(:position AS DOUBLE PRECISION) IS NULL THEN CAST(:reported AS TIMESTAMPTZ) END
            WHERE :force OR CAST(:position AS DOUBLE PRECISION) >= 30
                OR EXISTS (SELECT 1 FROM resume_positions WHERE user_id = :user AND video_id = :video)
            ON CONFLICT (user_id, video_id) DO UPDATE
            SET position_seconds = EXCLUDED.position_seconds,
                reported_at = GREATEST(resume_positions.reported_at, EXCLUDED.reported_at),
                last_played_at = GREATEST(resume_positions.last_played_at, EXCLUDED.last_played_at),
                protected_through_at = CASE WHEN EXCLUDED.position_seconds IS NULL
                    THEN GREATEST(resume_positions.protected_through_at, resume_positions.reported_at, EXCLUDED.reported_at)
                    ELSE resume_positions.protected_through_at END
            WHERE (EXCLUDED.reported_at > resume_positions.reported_at
                OR (EXCLUDED.reported_at < resume_positions.reported_at
                    AND EXTRACT(EPOCH FROM resume_positions.reported_at) - EXTRACT(EPOCH FROM EXCLUDED.reported_at) <= CAST(:clock_skew_seconds AS NUMERIC)))
                AND (resume_positions.protected_through_at IS NULL OR EXCLUDED.reported_at > resume_positions.protected_through_at)
                AND (:force OR ABS(EXCLUDED.position_seconds - COALESCE(resume_positions.position_seconds, 0)) >= 30)
            RETURNING id
            SQL, [
            'id' => Uuid::v7(),
            'user' => $userId,
            'video' => $videoId,
            'position' => $positionSeconds,
            'reported' => $reportedAt,
            'force' => $force,
            'clock_skew_seconds' => (string) $clockSkewSeconds,
        ], [
            'id' => 'uuid',
            'user' => 'uuid',
            'video' => 'uuid',
            'position' => $positionSeconds === null ? ParameterType::NULL : 'float',
            'reported' => 'datetime_immutable',
            'force' => ParameterType::BOOLEAN,
            'clock_skew_seconds' => ParameterType::STRING,
        ]) !== false;
    }

    public function clear(Uuid $userId, array $videoIds, DateTimeImmutable $clearedAt): void
    {
        $this->requireTransaction();
        $videos = [];
        foreach ($videoIds as $videoId) {
            $videos[$videoId->toString()] = $videoId;
        }
        ksort($videos, SORT_STRING);
        foreach (array_chunk($videos, 500) as $chunk) {
            $values = [];
            $parameters = [];
            $types = [];
            foreach ($chunk as $video) {
                $values[] = '(?, ?, ?, NULL, ?, NULL, ?)';
                array_push($parameters, Uuid::v7(), $userId, $video, $clearedAt, $clearedAt);
                array_push($types, 'uuid', 'uuid', 'uuid', 'datetime_immutable', 'datetime_immutable');
            }
            // DQL has no upsert; clearing retains the report fence even for files without a previous position.
            $this->connection->executeStatement(
                'INSERT INTO resume_positions (id, user_id, video_id, position_seconds, reported_at, last_played_at, protected_through_at) VALUES '
                . implode(', ', $values)
                . ' ON CONFLICT (user_id, video_id) DO UPDATE SET position_seconds = NULL, '
                . 'reported_at = GREATEST(resume_positions.reported_at, EXCLUDED.reported_at), '
                . 'protected_through_at = GREATEST(resume_positions.protected_through_at, resume_positions.reported_at, EXCLUDED.protected_through_at)',
                $parameters,
                $types,
            );
        }
    }

    private function requireTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('Resume positions must be written inside the title transaction.');
        }
    }
}
