<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

use App\Shared\Domain\Model\Uuid;

final readonly class SyncLibraryMessage
{
    public function __construct(
        public readonly Uuid $libraryId,
        public readonly bool $forceUpdate = false,
        public readonly bool $includeSongs = false,
        public readonly bool $includeArtists = false,
    ) {
    }
}
