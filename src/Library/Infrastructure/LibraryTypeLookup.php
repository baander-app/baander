<?php

declare(strict_types=1);

namespace App\Library\Infrastructure;

use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final readonly class LibraryTypeLookup implements LibraryTypeLookupInterface
{
    public function __construct(
        private LibraryRepositoryInterface $libraries,
        private LibraryLookup $lookup,
    ) {}

    #[\Override]
    public function findType(Uuid $libraryId): ?LibraryType
    {
        return $this->libraries->findByUuid($libraryId)?->getType();
    }

    #[\Override]
    public function resolveId(string $identifier, LibraryReadScope $scope): Uuid
    {
        return $this->lookup->byIdentifier($identifier, $scope)->getId();
    }
}
