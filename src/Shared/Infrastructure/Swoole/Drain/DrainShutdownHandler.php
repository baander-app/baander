<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerShutdownHandler;

/**
 * Ends the drain watchdog after the manager and every worker have exited, and exits
 * non-zero if the drain deadline was reached.
 */
final readonly class DrainShutdownHandler implements ServerShutdownHandler
{
    public function __construct(
        private DrainCoordinator $drain,
    ) {
    }

    public function handle(Server $server): void
    {
        $this->drain->finish();
    }
}
