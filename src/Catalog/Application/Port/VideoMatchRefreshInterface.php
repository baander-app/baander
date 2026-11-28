<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface VideoMatchRefreshInterface
{
    /** Queues the library's matched video titles and returns the number queued. */
    public function requestLibrary(Uuid $libraryId): int;

    /** Queues matched video titles across all libraries and returns the number queued. */
    public function requestAll(): int;
}
