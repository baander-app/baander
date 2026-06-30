<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Swoole;

use Psr\Container\ContainerInterface;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerShutdownHandler;

final class SchedulerProcessShutdownHandler implements ServerShutdownHandler
{
    public function __construct(
        private readonly ContainerInterface $schedulerProcessLocator,
    ) {
    }

    public function handle(Server $server): void
    {
        if (!$this->schedulerProcessLocator->has(SchedulerProcess::class)) {
            return;
        }

        try {
            $this->schedulerProcessLocator->get(SchedulerProcess::class)->shutdown();
        } catch (\Throwable) {
        }
    }
}
