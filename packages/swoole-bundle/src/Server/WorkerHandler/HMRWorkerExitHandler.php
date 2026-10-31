<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\WorkerHandler;

use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\Runtime\HMR\HotModuleReloadTimer;

final readonly class HMRWorkerExitHandler implements WorkerExitHandler
{
    public function __construct(
        private HotModuleReloadTimer $timer,
        private ?WorkerExitHandler $decorated = null,
    ) {}

    public function handle(Server $worker, int $workerId): void
    {
        $this->timer->stop();
        $this->decorated?->handle($worker, $workerId);
    }
}
