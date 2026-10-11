<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Application\Port\ServerDrainStateInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * While the server drains, requests still arrive on connections kept alive from before the stop.
 * Each answer carries Connection: close, so the client opens its next request elsewhere instead
 * of on a connection the server is about to close.
 *
 * Swoole sends the header but keeps the connection open; the master closes every connection
 * still open when the drain window ends.
 */
#[AsEventListener(event: 'kernel.response')]
final readonly class DrainingResponseListener
{
    public function __construct(
        private ServerDrainStateInterface $drain,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->drain->isDraining()) {
            $event->getResponse()->headers->set('Connection', 'close');
        }
    }
}
