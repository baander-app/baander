<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recommendation;

use App\Recommendation\Infrastructure\Swoole\RecommendationPoolWorker;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * The pool worker opens its own PostgreSQL connection, so this test commits its job rows
 * through a connection of its own, outside the DAMA transaction, and deletes them after.
 */
final class RecommendationPoolWorkerTest extends TestCase
{
    private \PDO $pdo;

    private string $databaseUrl;

    /** @var list<string> */
    private array $jobIds = [];

    private ?string $role = null;

    private string $phpZone;

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->databaseUrl = $url;
        $this->phpZone = date_default_timezone_get();

        $parts = parse_url($url);
        self::assertIsArray($parts);
        $this->pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'] ?? '', $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/')),
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->jobIds as $id) {
            $this->pdo->prepare('DELETE FROM recommendation_jobs WHERE id = ?')->execute([$id]);
        }
        if ($this->role !== null) {
            $this->pdo->exec(sprintf('DROP OWNED BY %1$s; DROP ROLE %1$s', $this->quotedRole()));
        }
        date_default_timezone_set($this->phpZone);
    }

    public function testDirectWorkerUpsertCreatesAndPreservesIndependentPublicIdentity(): void
    {
        $source = Uuid::v7()->toString();
        $target = Uuid::v7()->toString();
        $insert = new \ReflectionMethod(RecommendationPoolWorker::class, 'insertRecommendation');
        $worker = new RecommendationPoolWorker();
        try {
            $insert->invoke($worker, $this->pdo, $source, $target, 0.5, 'identity-fixture', null);
            $select = $this->pdo->prepare('SELECT id, public_id, score FROM recommendations WHERE source_id = ? AND target_id = ? AND name = ?');
            $select->execute([$source, $target, 'identity-fixture']);
            $first = $select->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($first);
            self::assertSame(21, strlen($first['public_id']));
            self::assertNotSame($first['id'], $first['public_id']);
            $insert->invoke($worker, $this->pdo, $source, $target, 0.9, 'identity-fixture', null);
            $select->execute([$source, $target, 'identity-fixture']);
            $second = $select->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($second);
            self::assertSame($first['public_id'], $second['public_id']);
            self::assertSame($first['id'], $second['id']);
            self::assertSame(0.9, (float) $second['score']);
            self::assertFalse($select->fetch());
        } finally {
            $delete = $this->pdo->prepare('DELETE FROM recommendations WHERE source_id = ? AND target_id = ? AND name = ?');
            $delete->execute([$source, $target, 'identity-fixture']);
        }
    }

    public function testAJobCancelledBeforeAWorkerPicksItUpStaysCancelledAndDoesNotRun(): void
    {
        $id = $this->job('cancelled');

        $result = json_decode((new RecommendationPoolWorker())->handle($this->payload($id)), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($result['success']);
        self::assertSame(['status' => 'cancelled', 'started_at' => null], $this->row($id));
    }

    public function testAPendingJobStillRunsToCompletion(): void
    {
        $id = $this->job('pending');

        $result = json_decode((new RecommendationPoolWorker())->handle($this->payload($id)), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($result['success']);
        $row = $this->row($id);
        self::assertSame('completed', $row['status']);
        self::assertNotNull($row['started_at']);
    }

    public function testAJobRecordsItsExactInstantsWhateverThePhpZone(): void
    {
        $id = $this->job('pending');
        // A zone whose offset is not a whole hour: a zone-less write would land 5h45m off.
        date_default_timezone_set('Asia/Kathmandu');
        $before = self::utcNow();

        $result = json_decode((new RecommendationPoolWorker())->handle($this->payload($id)), true, flags: JSON_THROW_ON_ERROR);

        $after = self::utcNow();
        self::assertTrue($result['success']);
        $statement = $this->pdo->prepare(
            "SELECT to_char(started_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS started_at,
                    to_char(completed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS completed_at,
                    to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS updated_at
             FROM recommendation_jobs WHERE id = ?",
        );
        $statement->execute([$id]);
        $instants = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($instants);
        // Microsecond text compares in time order; a whole-second or shifted value falls outside.
        foreach ($instants as $column => $instant) {
            self::assertIsString($instant, $column);
            self::assertGreaterThanOrEqual($before, $instant, $column);
            self::assertLessThanOrEqual($after, $instant, $column);
        }
    }

    public function testPercentEncodedCredentialsInTheDatabaseUrlConnect(): void
    {
        $privileges = $this->pdo->prepare('SELECT count(*) FROM pg_roles WHERE rolname = current_user AND (rolsuper OR rolcreaterole)');
        $privileges->execute();
        if ((int) $privileges->fetchColumn() !== 1) {
            self::markTestSkipped('The test database user cannot create roles.');
        }
        // Both need percent-encoding in a URL, as a generated password or a mail-style user name would.
        $this->role = 'pool@' . bin2hex(random_bytes(4));
        $password = 'p@ss:w/rd %+#?&';
        $this->pdo->exec(sprintf('CREATE ROLE %s LOGIN PASSWORD %s', $this->quotedRole(), $this->pdo->quote($password)));
        $this->pdo->exec(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %s', $this->quotedRole()));
        $id = $this->job('pending');

        $parts = parse_url($this->databaseUrl);
        self::assertIsArray($parts);
        $url = sprintf(
            '%s://%s:%s@%s:%d%s',
            $parts['scheme'] ?? 'postgresql',
            rawurlencode($this->role),
            rawurlencode($password),
            $parts['host'] ?? '',
            $parts['port'] ?? 5432,
            $parts['path'] ?? '',
        );

        $result = json_decode((new RecommendationPoolWorker())->handle($this->payload($id, $url)), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($result['success']);
        self::assertSame('completed', $this->row($id)['status']);
    }

    private function quotedRole(): string
    {
        return '"' . str_replace('"', '""', (string) $this->role) . '"';
    }

    private static function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function job(string $status): string
    {
        $id = (new Uuid())->toString();
        $this->jobIds[] = $id;
        $this->pdo->prepare(
            "INSERT INTO recommendation_jobs (id, public_id, is_full, status, total_songs, completed_songs, current_strategy, strategy_counts, metadata, created_at, updated_at)
             VALUES (?, ?, false, ?, 0, 0, '', '{}', '{}', NOW(), NOW())",
        )->execute([$id, (new PublicId())->toString(), $status]);

        return $id;
    }

    private function payload(string $id, ?string $databaseUrl = null): string
    {
        return json_encode(['type' => 'generate_recommendations', 'job_id' => $id, 'is_full' => false, 'database_url' => $databaseUrl ?? $this->databaseUrl], JSON_THROW_ON_ERROR);
    }

    /** @return array{status: string, started_at: string|null} */
    private function row(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT status, started_at FROM recommendation_jobs WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
