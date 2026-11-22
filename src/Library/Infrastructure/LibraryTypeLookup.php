<?php

declare(strict_types=1);

namespace App\Library\Infrastructure;

use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;

final readonly class LibraryTypeLookup implements LibraryTypeLookupInterface
{
    public function __construct(private LibraryRepositoryInterface $libraries) {}

    #[\Override]
    public function findType(Uuid $libraryId): ?LibraryType
    {
        return $this->libraries->findByUuid($libraryId)?->getType();
    }
}
