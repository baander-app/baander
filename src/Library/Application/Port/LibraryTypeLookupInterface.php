<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;

interface LibraryTypeLookupInterface
{
    /** Null when no library has the ID. */
    public function findType(Uuid $libraryId): ?LibraryType;
}
