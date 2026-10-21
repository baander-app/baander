<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface LibraryFileAvailabilityInterface
{
    /**
     * Last observed source availability, read from the index without filesystem checks.
     * Paths without an index row are unavailable until a scan confirms them.
     *
     * @param list<string> $paths Absolute source paths in the owning library.
     * @return array<string, bool> One entry for every distinct requested path.
     */
    public function findAvailabilityByPaths(Uuid $libraryId, array $paths): array;
}
