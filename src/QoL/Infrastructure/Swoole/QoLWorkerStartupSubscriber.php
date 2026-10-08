<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Service\StreamGovernor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restores the saved learning state in every HTTP worker, and starts the sampler
 * and mid-stream monitor in worker zero. Saved state carries no active streams and
 * no profile: the profile is shared through AlgorithmProfileTable.
 */
final class QoLWorkerStartupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ContainerInterface $services,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::NAME => 'onWorkerStarted'];
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
