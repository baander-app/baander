<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

final readonly class SyncGenresMessage
{
    public function __construct(
        public bool $forceUpdate = false,
        public bool $includeSongs = false,
    ) {
    }
}
