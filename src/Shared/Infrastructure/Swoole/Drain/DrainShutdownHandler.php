<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolShutdownHandler;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerShutdownHandler;

/**
 * The master's onShutdown, after the manager and every worker have exited: the CPU pool stops,
 * then the drain ends its watchdog (and exits non-zero if the drain deadline was reached).
 */
final readonly class DrainShutdownHandler implements ServerShutdownHandler
{
    public function __construct(
        private CpuProcessPoolShutdownHandler $cpuProcessPool,
        private DrainCoordinator $drain,
    ) {
    }

    public function handle(Server $server): void
    {
        $this->cpuProcessPool->handle($server);
        $this->drain->finish();
    }
}
