<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\Uuid;

final class LibraryProviderOrderState
{
    /** @param list<string> $providerIds */
    public function __construct(
        public readonly Uuid $libraryId,
        public array $providerIds,
    ) {}
}
