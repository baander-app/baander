<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

use App\Library\Domain\ValueObject\LibrarySlug;
use App\Shared\Domain\Model\Uuid;

/**
 * Runs the discovery of a library under the scan claim $claimId, which the sender took with
 * StartLibraryScanCommand, ScanAllLibrariesCommand or ClaimLibraryScanCommand. The scan renews
 * that claim and ends it with its outcome. If the claim ended meanwhile (a retried job) the scan
 * claims the free library again; if another scan holds a live claim, it stops with a conflict.
 * Without a claim ID the scan claims the library itself.
 */
final readonly class ScanLibraryCommand
{
    public function __construct(
        private LibrarySlug $librarySlug,
        private bool $rescan = false,
        private ?Uuid $claimId = null,
    ) {
    }

    /** @throws \InvalidArgumentException when the slug is malformed */
    public static function forSlug(string $slug, bool $rescan = false, ?Uuid $claimId = null): self
    {
        return new self(new LibrarySlug($slug), $rescan, $claimId);
    }

    public function getLibrarySlug(): LibrarySlug
    {
        return $this->librarySlug;
    }

    public function isRescan(): bool
    {
        return $this->rescan;
    }

    public function getClaimId(): ?Uuid
    {
        return $this->claimId;
    }
}
