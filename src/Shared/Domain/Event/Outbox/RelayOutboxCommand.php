<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

final readonly class RelayOutboxCommand
{
    public function __construct(
        public int $batchSize = 50,
    ) {
    }
}
