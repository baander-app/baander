<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Service\StreamGovernor;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\Swoole\Drain\WorkerDrainStartedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restores the saved learning state in every HTTP worker, and starts the sampler
 * and mid-stream monitor in worker zero. Saved state carries no active streams and
 * no profile: the profile is shared through AlgorithmProfileTable.
 *
 * When the server drains, worker zero saves its learning state, and as the worker
 * exits the sampler and monitor timers stop, so they do not hold the worker until
 * Swoole kills it. A reload does neither.
 */
final class QoLWorkerStartupSubscriber implements EventSubscriberInterface
{
    /** The container passes the drain state; it is optional only for tests. */
    public function __construct(
        private readonly ContainerInterface $services,
        private readonly LoggerInterface $logger,
        private readonly ?ServerDrainStateInterface $drain = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::NAME => 'onWorkerStarted',
            WorkerDrainStartedEvent::class => 'onWorkerDrainStarted',
            WorkerExitedEvent::NAME => 'onWorkerExited',
        ];
    }

    /** Worker zero saves the learning state; a failure is logged and the drain goes on. */
    public function onWorkerDrainStarted(WorkerDrainStartedEvent $event): void
    {
        if ($event->getWorkerId() !== 0) {
            return;
        }

        try {
            $this->services->get(LearningDataPersister::class)->save();
        } catch (\Throwable $exception) {
            $this->logger->error('QoL learning state was not saved during the drain', ['exception' => $exception]);
        }
    }

    /**
     * Runs on every WorkerExit of a stopping worker, outside any coroutine; clearing a timer
     * twice does nothing.
     */
    public function onWorkerExited(WorkerExitedEvent $event): void
    {
        if ($event->getWorkerId() !== 0 || $this->drain?->isDraining() !== true) {
            return;
        }

        if ($this->services->has(CpuGpuSampler::class)) {
            $this->services->get(CpuGpuSampler::class)->stopSampling();
        }
        if ($this->services->has(MidStreamMonitor::class)) {
            $this->services->get(MidStreamMonitor::class)->stopMonitoring();
        }
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if ($event->getServer()->taskworker) {
            return;
        }
        $firstWorker = $event->getWorkerId() === 0;

        try {
            if ($firstWorker && $this->services->has(CpuGpuSampler::class)) {
                $this->services->get(CpuGpuSampler::class)->startSampling();
            }

            if ($firstWorker && $this->services->has(MidStreamMonitor::class)) {
                $this->services->get(MidStreamMonitor::class)->startMonitoring();
            }

            $persister = $this->services->get(LearningDataPersister::class);
            $governor = $this->services->get(StreamGovernor::class);
            $fingerprint = $this->services->get(EncoderProfileFingerprintPortInterface::class);

            $savedState = $persister->load();
            $currentProfile = $fingerprint->getName();

            if ($savedState !== null) {
                $savedProfile = $savedState['encoder_profile'] ?? null;

                if ($savedProfile !== null && $currentProfile !== $savedProfile) {
                    $governor->resetLearning();
                    // Every worker sees the mismatch; one removes the stale file.
                    if ($firstWorker) {
                        $persister->cleanup();
                    }
                    $savedState = null;
                }
            }

            if ($savedState !== null) {
                $governor->importState($savedState['governor'] ?? []);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('QoL governor startup failed', ['exception' => $exception]);
        }
    }
}
