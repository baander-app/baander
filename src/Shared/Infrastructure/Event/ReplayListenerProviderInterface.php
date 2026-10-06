<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

/**
 * Contributes listeners to the outbox notification replay dispatcher.
 *
 * Owning contexts implement this so the Shared replay dispatcher never names
 * their events or services.
 */
interface ReplayListenerProviderInterface
{
    /**
     * @return iterable<array{class-string, callable}> Event class and listener pairs, in registration order.
     */
    public function replayListeners(): iterable;
}
