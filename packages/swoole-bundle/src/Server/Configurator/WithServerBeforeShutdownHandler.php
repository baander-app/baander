<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\Configurator;

use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerBeforeShutdownHandler;

/**
 * Registers the application's before-shutdown handler. Swoole keeps one BeforeShutdown callback per
 * server, so this configurator is its only owner; without a handler nothing is registered.
 */
final readonly class WithServerBeforeShutdownHandler implements Configurator
{
    public function __construct(private ?ServerBeforeShutdownHandler $handler = null) {}

    public function configure(Server $server): void
    {
        if ($this->handler === null) {
            return;
        }

        $server->on('BeforeShutdown', $this->handler->handle(...));
    }
}
