<?php

declare(strict_types=1);

namespace App\Library\Application\DTO;

use App\Library\Domain\Model\Library;

/** The outcome of claiming every library for a scan. */
final readonly class LibraryScanClaimResult
{
    /**
     * @param list<LibraryScanClaim> $claimed the libraries now claimed for a scan, with their claims
     * @param list<Library>          $skipped the libraries a live claim of another scan held
     */
    public function __construct(
        public array $claimed,
        public array $skipped,
    ) {
    }
}
