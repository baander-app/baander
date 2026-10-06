<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use Symfony\Component\EventDispatcher\EventDispatcher;

/** Deliberately excludes capture, session-local effects and transcode work. */
final class NotificationReplayDispatcher extends EventDispatcher
{
    /** @param iterable<ReplayListenerProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        parent::__construct();
        foreach ($providers as $provider) {
            foreach ($provider->replayListeners() as [$eventClass, $listener]) {
                $this->addListener($eventClass, $listener);
            }
        }
    }
}
