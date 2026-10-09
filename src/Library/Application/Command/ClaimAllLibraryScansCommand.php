<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Claims every library that no live claim holds, for scans the sender runs itself, as
 * `app:library:scan --all` does inline. The sender must end each claim it does not run to its
 * end with EndLibraryScanClaimCommand.
 */
final readonly class ClaimAllLibraryScansCommand
{
}
