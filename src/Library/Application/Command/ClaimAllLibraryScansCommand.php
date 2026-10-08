<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Claims every library that is not scanning, for scans the sender runs itself, as
 * `app:library:scan --all` does inline. The sender must release each claim it does not
 * run to its end.
 */
final readonly class ClaimAllLibraryScansCommand
{
}
