<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface LibraryTypeLookupInterface
{
    /** Null when no library has the ID. */
    public function findType(Uuid $libraryId): ?LibraryType;

    /** Resolve a visible library's public ID or slug. */
    public function resolveId(string $identifier, LibraryReadScope $scope): Uuid;
}
