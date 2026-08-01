<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\Event\TranscodeJobFailed;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class TranscodeJobEventSubscriber
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    #[AsEventListener(event: TranscodeJobCompleted::class)]
    public function onJobCompleted(TranscodeJobCompleted $event): void
    {
        $this->logger->info('Transcode job completed', [
            'jobId' => $event->getJobId()->toString(),
            'totalSegments' => $event->getTotalSegments(),
        ]);
    }

    #[AsEventListener(event: TranscodeJobFailed::class)]
    public function onJobFailed(TranscodeJobFailed $event): void
    {
        $this->logger->error('Transcode job failed', [
            'jobId' => $event->getJobId()->toString(),
            'reason' => $event->getReason(),
        ]);
    }
}
