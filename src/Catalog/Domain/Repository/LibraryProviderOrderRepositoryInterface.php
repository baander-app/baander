<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Shared\Domain\Model\Uuid;

interface LibraryProviderOrderRepositoryInterface
{
    public function findByLibrary(Uuid $libraryId): ?LibraryProviderOrder;

    public function save(LibraryProviderOrder $order): void;
}
