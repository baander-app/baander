<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Swoole;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\ServerStartedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Cleans up orphaned recommendation jobs on server startup.
 *
 * Jobs in 'pending' status that were created before the current server start
 * are marked as 'failed' since they will never be picked up by the CPU pool
 * (dispatch is in-memory only and lost on restart).
 *
 * It runs in the server master's onStart. A failure, such as an unreachable database, is logged
 * and the server starts anyway, and each statement is limited to STATEMENT_TIMEOUT so the master
 * never waits long on the database while a stop request may be arriving.
 */
final class RecommendationJobCleanupService
{
    private const string STATEMENT_TIMEOUT = '5s';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
        // The master's onStart coroutine is not one the bundle releases pooled services for.
        private readonly ?CoWrapper $coWrapper = null,
    ) {
    }

    #[AsEventListener(ServerStartedEvent::NAME)]
    public function onServerStarted(ServerStartedEvent $event): void
    {
        $this->coWrapper?->defer();

        try {
            [$pendingCount, $progressCount] = $this->entityManager->getConnection()->transactional(
                static function (Connection $conn): array {
                    $conn->executeStatement(sprintf("SET LOCAL statement_timeout = '%s'", self::STATEMENT_TIMEOUT));

                    // Mark orphaned pending jobs as failed (jobs older than 1 minute with no start)
                    $pendingCount = $conn->executeStatement("
                        UPDATE recommendation_jobs
                        SET status = 'failed',
                            fail_reason = 'Job orphaned by server restart',
                            completed_at = NOW(),
                            updated_at = NOW()
                        WHERE status = 'pending'
                          AND started_at IS NULL
                          AND created_at < NOW() - INTERVAL '1 minute'
                    ");

                    // Mark orphaned in_progress jobs as failed (jobs that started but haven't updated in 5 minutes)
                    $progressCount = $conn->executeStatement("
                        UPDATE recommendation_jobs
                        SET status = 'failed',
                            fail_reason = 'Job interrupted (worker crash or timeout)',
                            completed_at = NOW(),
                            updated_at = NOW()
                        WHERE status = 'in_progress'
                          AND updated_at < NOW() - INTERVAL '5 minutes'
                    ");

                    return [(int) $pendingCount, (int) $progressCount];
                },
            );
        } catch (\Throwable $error) {
            $this->logger?->warning('Could not clean up orphaned recommendation jobs at server start', [
                'error' => $error->getMessage(),
            ]);

            return;
        }

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
