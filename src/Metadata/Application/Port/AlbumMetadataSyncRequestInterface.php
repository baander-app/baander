<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Lets another context ask for a metadata sync of newly created albums.
 *
 * Queues one asynchronous sync per album while `metadata.auto_sync` is on and
 * does nothing while it is off. The toggle is read on every call. Call it only
 * after the albums are committed, so the sync can find them.
 */
interface AlbumMetadataSyncRequestInterface
{
    public function requestSync(Uuid ...$albumIds): void;
}
