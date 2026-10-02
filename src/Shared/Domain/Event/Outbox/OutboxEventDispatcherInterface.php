<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use App\Shared\Domain\Event\DomainEventInterface;

interface OutboxEventDispatcherInterface
{
    public function dispatch(DomainEventInterface $event, int $outboxId): void;
}
