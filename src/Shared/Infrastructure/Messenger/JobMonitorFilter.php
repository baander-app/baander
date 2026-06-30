<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

final readonly class JobMonitorFilter
{
    public function __construct(
        public ?string $status = null,
        public ?string $name = null,
        public ?string $queue = null,
    ) {
    }
}
