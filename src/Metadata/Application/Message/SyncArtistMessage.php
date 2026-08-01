<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

use App\Shared\Domain\Model\Uuid;

final readonly class SyncArtistMessage
{
    public function __construct(
        public readonly Uuid $artistId,
        public readonly bool $forceUpdate = false,
    ) {
    }
}
