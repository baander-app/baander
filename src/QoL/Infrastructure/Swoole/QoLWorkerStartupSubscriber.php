<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Service\StreamGovernor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Starts governor monitoring and restores learning only in worker zero. */
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
        if ($event->getWorkerId() !== 0) {
            return;
        }

        try {
            if ($this->services->has(CpuGpuSampler::class)) {
                $this->services->get(CpuGpuSampler::class)->startSampling();
            }

            if ($this->services->has(MidStreamMonitor::class)) {
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
                    $persister->cleanup();
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
