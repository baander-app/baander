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

    protected function setUp(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->databaseUrl = $url;

        $parts = parse_url($url);
        self::assertIsArray($parts);
        $this->pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'] ?? '', $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/')),
            $parts['user'] ?? null,
            isset($parts['pass']) ? urldecode($parts['pass']) : null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->jobIds as $id) {
            $this->pdo->prepare('DELETE FROM recommendation_jobs WHERE id = ?')->execute([$id]);
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

    private function payload(string $id): string
    {
        return json_encode(['type' => 'generate_recommendations', 'job_id' => $id, 'is_full' => false, 'database_url' => $this->databaseUrl], JSON_THROW_ON_ERROR);
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
