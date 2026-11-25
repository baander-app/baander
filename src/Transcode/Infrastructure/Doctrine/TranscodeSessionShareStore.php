<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\HeldTranscodeSession;
use App\Transcode\Application\Port\TranscodeSessionSharePort;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/** SQL because DQL has neither advisory locks nor a row lock on one table of a join (FOR UPDATE OF). */
final readonly class TranscodeSessionShareStore implements TranscodeSessionSharePort
{
    private const int USER_STARTS_LOCK_SEED = 20261125160000;

    /** Session states that hold a job; matches stale session expiry. */
    private const string HOLDING = "s.state IN ('pending', 'preparing', 'active', 'paused')";

    /** Execution outcomes a session holds: running, waiting for a slot or finished. */
    private const string HELD = "r.outcome IN ('pending', 'accepted', 'ready', 'complete')";

    private const string SELECT_HELD = 'SELECT s.id, s.job_id, s.video_id, j.request_id, j.quality_tier_name, s.updated_at,'
        . " r.outcome IN ('pending', 'accepted', 'ready') AS holds_slot"
        . ' FROM transcode_sessions s'
        . ' JOIN transcode_runtime_state r ON r.job_id = s.job_id AND r.session_id = s.id'
        . ' JOIN transcode_jobs j ON j.id = s.job_id';

    public function __construct(private Connection $connection)
    {
    }

    public function lockUser(Uuid $userId): void
    {
        if (!$this->connection->isTransactionActive()) {
            // Outside a transaction the lock would end with its own statement and serialize nothing.
            throw new \LogicException('Lock the user\'s session starts inside the start\'s transaction.');
        }
        // An advisory lock rather than a row lock: signing and every playback report update the
        // user's session rows, and a start must not queue behind them.
        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:user, ' . self::USER_STARTS_LOCK_SEED . '))',
            ['user' => $userId->toString()],
        )->free();
    }

    public function heldBy(Uuid $userId): array
    {
        return array_map($this->held(...), $this->connection->fetchAllAssociative(
            self::SELECT_HELD . ' WHERE s.user_id = :user AND ' . self::HOLDING . ' AND ' . self::HELD . ' ORDER BY s.updated_at, s.id',
            ['user' => $userId->toString()],
        ));
    }

    public function occupiedVideoSlots(): int
    {
        // A pending execution past its admission deadline has no start waiting for it; a worker refuses it.
        return (int) $this->connection->fetchOne(
            "SELECT count(*) FROM transcode_runtime_state WHERE outcome IN ('accepted', 'ready')"
            . " OR (outcome = 'pending' AND admission_deadline > clock_timestamp())",
        );
    }

    public function oldestSlotHolder(DateTimeImmutable $silentSince, array $excluding): ?HeldTranscodeSession
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT_HELD . ' WHERE ' . self::HOLDING . " AND r.outcome IN ('pending', 'accepted', 'ready')"
            . ' AND s.updated_at <= :silent_since AND s.id <> ALL (CAST(:excluding AS uuid[]))'
            . ' ORDER BY s.updated_at, s.id LIMIT 1',
            ['silent_since' => $silentSince, 'excluding' => self::uuidArray($excluding)],
            ['silent_since' => Types::DATETIME_IMMUTABLE],
        );

        return $row === false ? null : $this->held($row);
    }

    public function lock(Uuid $sessionId): ?HeldTranscodeSession
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Ending a held session requires the transaction that ends it.');
        }
        // The execution row first, as playback controls and the worker take it before the session row.
        $this->connection->executeQuery(
            'SELECT r.job_id FROM transcode_runtime_state r JOIN transcode_sessions s ON s.job_id = r.job_id WHERE s.id = :session FOR UPDATE OF r',
            ['session' => $sessionId->toString()],
        )->free();
        // The session row too: a stream signing renews the sign of life without the execution lock.
        $row = $this->connection->fetchAssociative(
            self::SELECT_HELD . ' WHERE s.id = :session AND ' . self::HOLDING . ' AND ' . self::HELD . ' FOR UPDATE OF s',
            ['session' => $sessionId->toString()],
        );

        return $row === false ? null : $this->held($row);
    }

    public function end(HeldTranscodeSession $session): void
    {
        $ended = $this->connection->executeStatement(
            "UPDATE transcode_sessions SET state = 'cancelled', updated_at = clock_timestamp() WHERE id = :session AND state IN ('pending', 'preparing', 'active', 'paused')",
            ['session' => $session->sessionId->toString()],
        );
        if ($ended === 1) {
            // Released, not cancelled: without a session the job is orphaned, which job cleanup removes.
            $this->connection->executeStatement(
                'UPDATE transcode_jobs SET reference_count = GREATEST(0, reference_count - 1), updated_at = clock_timestamp() WHERE id = :job',
                ['job' => $session->jobId->toString()],
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function held(array $row): HeldTranscodeSession
    {
        $signOfLife = $this->connection->convertToPHPValue($row['updated_at'], Types::DATETIME_IMMUTABLE);
        if (!$signOfLife instanceof DateTimeImmutable) {
            throw new \UnexpectedValueException('A transcode session has no sign of life.');
        }

        return new HeldTranscodeSession(
            Uuid::fromString($row['id']),
            Uuid::fromString($row['job_id']),
            Uuid::fromString($row['video_id']),
            $row['request_id'],
            $row['quality_tier_name'],
            $signOfLife,
            (bool) $row['holds_slot'],
        );
    }

    /** @param list<Uuid> $ids */
    private static function uuidArray(array $ids): string
    {
        return '{' . implode(',', array_map(static fn (Uuid $id): string => $id->toString(), $ids)) . '}';
    }
}
