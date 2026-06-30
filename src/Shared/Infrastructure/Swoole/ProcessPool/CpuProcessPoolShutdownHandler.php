<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\ProcessPool;

use Psr\Container\ContainerInterface;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerShutdownHandler;

final class CpuProcessPoolShutdownHandler implements ServerShutdownHandler
{
    public function __construct(
        private readonly ContainerInterface $cpuProcessPoolLocator,
    ) {}

    public function handle(Server $server): void
    {
        if (!$this->cpuProcessPoolLocator->has(CpuProcessPool::class)) {
            return;
        }

        try {
            $this->cpuProcessPoolLocator->get(CpuProcessPool::class)->shutdown();
        } catch (\Throwable) {
        }
    }
}
