<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Swoole;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\ServerStartedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Cleans up orphaned recommendation jobs on server startup.
 *
 * Jobs in 'pending' status that were created before the current server start
 * are marked as 'failed' since they will never be picked up by the CPU pool
 * (dispatch is in-memory only and lost on restart).
 */
final class RecommendationJobCleanupService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[AsEventListener(ServerStartedEvent::NAME)]
    public function onServerStarted(ServerStartedEvent $event): void
    {
        $conn = $this->entityManager->getConnection();

        // Mark orphaned pending jobs as failed (jobs older than 1 minute with no start)
        $pendingStmt = $conn->prepare("
            UPDATE recommendation_jobs
            SET status = 'failed',
                fail_reason = 'Job orphaned by server restart',
                completed_at = NOW(),
                updated_at = NOW()
            WHERE status = 'pending'
              AND started_at IS NULL
              AND created_at < NOW() - INTERVAL '1 minute'
        ");
        $pendingCount = $pendingStmt->executeStatement();

        // Mark orphaned in_progress jobs as failed (jobs that started but haven't updated in 5 minutes)
        $progressStmt = $conn->prepare("
            UPDATE recommendation_jobs
            SET status = 'failed',
                fail_reason = 'Job interrupted (worker crash or timeout)',
                completed_at = NOW(),
                updated_at = NOW()
            WHERE status = 'in_progress'
              AND updated_at < NOW() - INTERVAL '5 minutes'
        ");
        $progressCount = $progressStmt->executeStatement();

        $total = $pendingCount + $progressCount;
        if ($total > 0) {
            $this->logger?->info('Cleaned up orphaned recommendation jobs', [
                'pending' => $pendingCount,
                'in_progress' => $progressCount,
                'total' => $total,
            ]);
        }
    }
}
