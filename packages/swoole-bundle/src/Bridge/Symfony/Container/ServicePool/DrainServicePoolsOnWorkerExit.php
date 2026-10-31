<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool;

use Swoole\Server;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Server\WorkerHandler\WorkerExitHandler;
use Throwable;

final class DrainServicePoolsOnWorkerExit implements WorkerExitHandler
{
    private bool $draining = false;

    public function __construct(
        private readonly ServicePoolContainer $servicePools,
        private readonly CoWrapper $coWrapper,
        private readonly Swoole $swoole,
        private readonly ?WorkerExitHandler $decorated = null,
    ) {}

    public function handle(Server $worker, int $workerId): void
    {
        $this->decorated?->handle($worker, $workerId);
        if ($this->draining) {
            return;
        }

        // Destructors may use hooked I/O; WorkerStop has already lost its reactor.
        $this->draining = true;
        try {
            $this->coWrapper->go(function (): void {
                try {
                    $this->servicePools->drain($this->swoole->getCoroutineId());
                } catch (Throwable $error) {
                    $this->report($error);
                } finally {
                    $this->draining = false;
                }
            });
        } catch (Throwable $error) {
            $this->draining = false;
            $this->report($error);
        }
    }

    private function report(Throwable $error): void
    {
        error_log(sprintf('Service pool worker-exit drain failed: %s: %s', $error::class, $error->getMessage()));
    }
}
