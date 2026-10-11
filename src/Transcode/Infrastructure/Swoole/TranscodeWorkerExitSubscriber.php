<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\FailInterruptedTranscodeSessionsCommand;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * When a worker stops because the server drains, its encoding loops stop and the FFmpeg
 * processes it started for video streams are killed, so none outlives the server. Each loop
 * releases its loop lock, so a session started after the restart can take the job over without
 * waiting for the lock to expire. Sessions are not resumed across a restart: the live sessions of
 * each stopped job end as failed, so a client that asks again gets a new session with a running
 * loop rather than the old one with none.
 *
 * Failing the sessions writes to PostgreSQL, so it runs last, after every encoder has stopped. A
 * database that is down or slow cannot keep an encoder running: an error is logged, and a hung
 * query ends when Swoole stops the worker at max_wait_time.
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
        private readonly MessageBusInterface $commandBus,
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
            $stoppedJobs = [];
            try {
                $stoppedJobs = $this->loops->stopAllLoops();
            } catch (\Throwable $error) {
                $this->logger->error('Transcode loops did not stop as the worker exits', ['exception' => $error]);
            }
            // Streams no loop owns any more, such as one whose loop failed to clean up.
            try {
                $this->streams->stopAll();
            } catch (\Throwable $error) {
                $this->logger->error('Video encoders did not stop as the worker exits', ['exception' => $error]);
            }
            if ($stoppedJobs === []) {
                return;
            }
            try {
                $this->commandBus->dispatch(new FailInterruptedTranscodeSessionsCommand($stoppedJobs));
            } catch (\Throwable $error) {
                $this->logger->error('Interrupted transcode sessions were not ended as the worker exits', [
                    'jobIds' => array_map(static fn (Uuid $jobId): string => $jobId->toString(), $stoppedJobs),
                    'exception' => $error,
                ]);
            }
        };
        if ($this->coWrapper !== null) {
            $this->coWrapper->go($stop);
        } else {
            $stop();
        }
    }
}
