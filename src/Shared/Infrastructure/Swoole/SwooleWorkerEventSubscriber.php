<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\ServerStartedEvent;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerErrorEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SwooleWorkerEventSubscriber implements EventSubscriberInterface
{
    private bool $shuttingDown = false;

    public function __construct(
        private readonly SwooleWorkerEventBuffer $buffer,
        private readonly ?ContainerInterface $cpuProcessPoolLocator = null,
        private readonly ?WebSocketPusher $webSocketPusher = null,
        private readonly ?WebSocketConnectionRegistry $webSocketRegistry = null,
        private readonly ?RedisClientFactory $redisClientFactory = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ContainerInterface $qolServicesLocator = null,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ServerStartedEvent::NAME => 'onServerStarted',
            WorkerStartedEvent::NAME => 'onWorkerStarted',
            WorkerStoppedEvent::NAME => 'onWorkerStopped',
            WorkerErrorEvent::NAME => 'onWorkerError',
        ];
    }

    public function onServerStarted(ServerStartedEvent $event): void
    {
        $server = $event->getServer();
        $pool = null;

        try {
            if ($this->cpuProcessPoolLocator !== null && $this->cpuProcessPoolLocator->has(CpuProcessPool::class)) {
                $pool = $this->cpuProcessPoolLocator->get(CpuProcessPool::class);
            }
        } catch (\Throwable) {
        }

        \Swoole\Process::signal(SIGINT, function () use ($server, $pool): void {
            $this->shuttingDown = true;
            $t = microtime(true);
            echo "\n // Shutting down server...\n";

            if ($pool !== null && $pool->isRunning()) {
                echo " // Stopping CPU process pool...\n";
                $pool->shutdown();
                printf(" // CPU process pool stopped (%dms)\n", (int) ((microtime(true) - $t) * 1000));
            }

            $maxWait = (int) ($server->setting['max_wait_time'] ?? 5);
            printf(" // Draining workers (timeout: %ds)...\n", $maxWait);
            $server->shutdown();

            // $server->shutdown() from a custom signal handler bypasses
            // Swoole's default max_wait_time enforcement. Force-exit after
            // the configured timeout to prevent indefinite hangs.
            \Swoole\Timer::after($maxWait * 1000, function () use ($maxWait): void {
                printf(" // Workers did not exit within %ds, force-stopping\n", $maxWait);
                posix_kill(posix_getpid(), SIGKILL);
            });
        });
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $workerId = $event->getWorkerId();
        $this->buffer->push('started', $workerId);
        $this->logger?->info('Swoole worker started', ['workerId' => $workerId]);

        if ($workerId === 0) {
            try {
                if ($this->cpuProcessPoolLocator !== null && $this->cpuProcessPoolLocator->has(CpuProcessPool::class)) {
                    $this->cpuProcessPoolLocator->get(CpuProcessPool::class)->startHealthCheck();
                }
            } catch (\Throwable) {
            }

            // QoL Governor — start sampler and monitor on worker 0
            try {
                if ($this->qolServicesLocator !== null) {
                    if ($this->qolServicesLocator->has(\App\QoL\Infrastructure\Swoole\CpuGpuSampler::class)) {
                        $this->qolServicesLocator->get(\App\QoL\Infrastructure\Swoole\CpuGpuSampler::class)->startSampling();
                    }
                    if ($this->qolServicesLocator->has(\App\QoL\Infrastructure\Swoole\MidStreamMonitor::class)) {
                        $this->qolServicesLocator->get(\App\QoL\Infrastructure\Swoole\MidStreamMonitor::class)->startMonitoring();
                    }

                    // Hardware change detection: compare EncoderProfile fingerprint
                    $persister = $this->qolServicesLocator->get(\App\QoL\Infrastructure\Swoole\LearningDataPersister::class);
                    $governor = $this->qolServicesLocator->get(\App\QoL\Domain\Service\StreamGovernor::class);
                    $prober = $this->qolServicesLocator->get(\App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber::class);

                    $savedState = $persister->load();
                    $currentProfile = $prober->getProfile();

                    if ($savedState !== null) {
                        $savedProfile = $savedState['encoder_profile'] ?? null;
                        if ($savedProfile !== null && $currentProfile->getName() !== $savedProfile) {
                            $governor->resetLearning();
                            $persister->cleanup();
                            $savedState = null;
                        }
                    }

                    // Load persisted governor state (if not reset)
                    if ($savedState !== null) {
                        $governor->importState($savedState['governor'] ?? []);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->error('QoL governor startup failed', ['exception' => $e]);
            }
        }

        $server = $event->getServer();
        if ($server !== null) {
            $this->webSocketPusher?->setServer($server);
            $this->webSocketRegistry?->setWorkerId($server->worker_id);
        }
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->buffer->push('stopped', $event->getWorkerId());
        $this->logger?->info('Swoole worker stopped', ['workerId' => $event->getWorkerId()]);

        $this->redisClientFactory?->dispose();

        if ($this->shuttingDown) {
            printf(" // Worker %d stopped\n", $event->getWorkerId());
        }
    }

    public function onWorkerError(WorkerErrorEvent $event): void
    {
        $this->buffer->push('error', $event->getWorkerId());
        $this->logger?->error('Swoole worker error', ['workerId' => $event->getWorkerId()]);
    }
}
