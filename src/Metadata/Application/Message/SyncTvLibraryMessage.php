<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

use App\Shared\Domain\Model\Uuid;

final readonly class SyncTvLibraryMessage
{
    public function __construct(
        public Uuid $libraryId,
        public bool $forceUpdate = false,
    ) {}
}
