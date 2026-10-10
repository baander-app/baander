<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recommendation\Infrastructure;

use App\Recommendation\Infrastructure\Swoole\RecommendationJobCleanupService;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\ServerStartedEvent;

/**
 * The startup cleanup fails orphaned jobs by comparing their instants with a cutoff. Instants
 * are microsecond-exact, so a job one microsecond on the near side of the cutoff survives.
 */
final class RecommendationJobCleanupServiceTest extends TestCase
{
    public function testItFailsJobsPastTheirCutoffAndKeepsOnesAMicrosecondLater(): void
    {
        // The test transaction fixes NOW(), so the cleanup sees the same cutoffs as these rows.
        $pendingPast = $this->job('pending', "NOW() - INTERVAL '1 minute' - INTERVAL '1 microsecond'", 'NOW()');
        $pendingAtCutoff = $this->job('pending', "NOW() - INTERVAL '1 minute'", 'NOW()');
        $runningPast = $this->job('in_progress', 'NOW()', "NOW() - INTERVAL '5 minutes' - INTERVAL '1 microsecond'");
        $runningAtCutoff = $this->job('in_progress', 'NOW()', "NOW() - INTERVAL '5 minutes'");

        (new RecommendationJobCleanupService($this->entityManager))->onServerStarted(
            (new \ReflectionClass(ServerStartedEvent::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame('failed', $this->jobStatus($pendingPast));
        self::assertSame('pending', $this->jobStatus($pendingAtCutoff));
        self::assertSame('failed', $this->jobStatus($runningPast));
        self::assertSame('in_progress', $this->jobStatus($runningAtCutoff));
    }

    /** $createdAt and $updatedAt are SQL expressions, so that they share the transaction's NOW(). */
    private function job(string $status, string $createdAt, string $updatedAt): string
    {
        $id = Uuid::generate()->toString();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO recommendation_jobs (id, public_id, is_full, status, total_songs, completed_songs, current_strategy, strategy_counts, metadata, created_at, updated_at)
             VALUES (:id, :publicId, false, :status, 0, 0, '', '{}', '{}', {$createdAt}, {$updatedAt})",
            ['id' => $id, 'publicId' => (new PublicId())->toString(), 'status' => $status],
        );

        return $id;
    }

    private function jobStatus(string $id): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM recommendation_jobs WHERE id = :id', ['id' => $id]);
    }
}
