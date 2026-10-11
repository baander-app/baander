<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\ServerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerErrorEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SwooleWorkerEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SwooleWorkerEventBuffer $buffer,
        private readonly ?ContainerInterface $cpuProcessPoolLocator = null,
        private readonly ?WebSocketPusher $webSocketPusher = null,
        private readonly ?WebSocketConnectionRegistry $webSocketRegistry = null,
        private readonly ?RedisClientFactory $redisClientFactory = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
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

    /**
     * The CPU pool's health check runs in the master until the drain ends it (DrainCoordinator).
     * SIGINT starts the same drain as SIGTERM: Server::shutdown() sends SIGTERM to the master.
     */
    public function onServerStarted(ServerStartedEvent $event): void
    {
        $server = $event->getServer();
        $this->cpuProcessPool()?->startHealthCheck();

        \Swoole\Process::signal(SIGINT, static function () use ($server): void {
            $server->shutdown();
        });
    }

    private function cpuProcessPool(): ?CpuProcessPool
    {
        if ($this->cpuProcessPoolLocator === null || !$this->cpuProcessPoolLocator->has(CpuProcessPool::class)) {
            return null;
        }

        return $this->cpuProcessPoolLocator->get(CpuProcessPool::class);
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $workerId = $event->getWorkerId();
        $this->buffer->push('started', $workerId);
        $this->logger?->info('Swoole worker started', ['workerId' => $workerId]);

        $server = $event->getServer();
        $this->webSocketPusher?->setServer($server);
        $this->webSocketRegistry?->setWorkerId($server->worker_id);
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->buffer->push('stopped', $event->getWorkerId());
        $this->logger?->info('Swoole worker stopped', ['workerId' => $event->getWorkerId()]);

        $this->redisClientFactory?->dispose();
    }

    public function onWorkerError(WorkerErrorEvent $event): void
    {
        $this->buffer->push('error', $event->getWorkerId());
        $this->logger?->error('Swoole worker error', ['workerId' => $event->getWorkerId()]);
    }
}
