<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

use App\Library\Domain\ValueObject\LibrarySlug;

/**
 * Runs the discovery of a library whose scan claim the sender holds (StartLibraryScanCommand
 * or ClaimLibraryScanCommand). Completing or failing the scan ends the claim.
 */
final readonly class ScanLibraryCommand
{
    public function __construct(
        private LibrarySlug $librarySlug,
        private bool $rescan = false,
    ) {
    }

    /** @throws \InvalidArgumentException when the slug is malformed */
    public static function forSlug(string $slug, bool $rescan = false): self
    {
        return new self(new LibrarySlug($slug), $rescan);
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
