<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

use App\Shared\Domain\Model\Uuid;

final readonly class SyncSongMessage
{
    public function __construct(
        public readonly Uuid $songId,
        public readonly bool $forceUpdate = false,
    ) {
    }
}
