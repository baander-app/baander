<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

use App\Library\Domain\ValueObject\LibrarySlug;

final readonly class ScanLibraryCommand
{
    public function __construct(
        private LibrarySlug $librarySlug,
        private bool $rescan = false,
    ) {
    }

    public function getLibrarySlug(): LibrarySlug
    {
        return $this->librarySlug;
    }

    public function isRescan(): bool
    {
        return $this->rescan;
    }
}
