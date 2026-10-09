<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Dispatches a message synchronously and returns its handler's result, for controllers whose
 * actions run an Application use case. A handler's exception reaches ExceptionSubscriber, which
 * maps it to its outcome response.
 *
 * @property-read MessageBusInterface $bus
 */
trait DispatchesMessagesTrait
{
    private function dispatch(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
