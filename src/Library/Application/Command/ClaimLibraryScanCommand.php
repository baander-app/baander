<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Claims a library for a scan the sender runs itself, as `app:library:scan` does inline.
 * The sender must release the claim if it cannot run the scan to its end.
 */
final readonly class ClaimLibraryScanCommand
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
    ) {
    }
}
