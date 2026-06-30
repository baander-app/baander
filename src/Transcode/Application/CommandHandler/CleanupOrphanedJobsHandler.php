<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CleanupOrphanedJobsHandler
{
    public function __construct(
        private readonly TranscodeJobPortInterface $jobPort,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CleanupOrphanedJobsCommand $command): int
    {
        return $this->jobPort->cleanupOrphanedJobs();
    }
}
