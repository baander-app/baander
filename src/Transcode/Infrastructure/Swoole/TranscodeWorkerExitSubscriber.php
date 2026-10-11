<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Application\Port\ServerDrainStateInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a worker stops because the server drains, its encoding loops stop and the FFmpeg
 * processes it started for video streams are killed, so none outlives the server. Each loop
 * releases its loop lock, so the restarted server resumes the job without waiting for the lock
 * to expire.
 *
 * WorkerExit runs again and again until the worker's event loop is empty and outside any
 * coroutine, so the stop runs once, in a coroutine of its own that releases its pooled services.
 * A reload never sets the drain flag: a reloading worker keeps its encoders running as before.
 */
final class TranscodeWorkerExitSubscriber implements EventSubscriberInterface
{
    private bool $stopped = false;

    /** The container passes the CoWrapper; it is optional only for tests. */
    public function __construct(
        private readonly ServerDrainStateInterface $drain,
        private readonly TranscodeSessionSubscriber $loops,
        private readonly TranscodeStreamManager $streams,
        private readonly LoggerInterface $logger,
        private readonly ?CoWrapper $coWrapper = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerExitedEvent::NAME => 'onWorkerExited'];
    }

    public function onWorkerExited(WorkerExitedEvent $event): void
    {
        if ($this->stopped || !$this->drain->isDraining()) {
            return;
        }
        $this->stopped = true;

        $stop = function (): void {
            try {
                $this->loops->stopAllLoops();
            } catch (\Throwable $error) {
                $this->logger->error('Transcode loops did not stop as the worker exits', ['exception' => $error]);
            }
            // Streams no loop owns any more, such as one whose loop failed to clean up.
            try {
                $this->streams->stopAll();
            } catch (\Throwable $error) {
                $this->logger->error('Video encoders did not stop as the worker exits', ['exception' => $error]);
            }
        };
        if ($this->coWrapper !== null) {
            $this->coWrapper->go($stop);
        } else {
            $stop();
        }
    }
}
