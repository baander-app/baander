<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

use App\Shared\Domain\Model\Uuid;

final readonly class SyncAlbumMessage
{
    public function __construct(
        public readonly Uuid $albumId,
        public readonly bool $forceUpdate = false,
    ) {
    }
}
