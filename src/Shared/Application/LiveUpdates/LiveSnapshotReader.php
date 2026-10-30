<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

use App\Shared\Domain\Model\Uuid;

interface LiveSnapshotReader
{
    /** @throws LiveAccessDenied When the current principal no longer has access. */
    public function read(Uuid $principal): LiveSnapshot;
}
