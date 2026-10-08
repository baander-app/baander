<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Claims every library that is not scanning and queues a scan for each: the admin panel's
 * POST /api/libraries/scan-all. `app:library:scan --all` claims with ClaimAllLibraryScansCommand
 * and runs the scans in its own process.
 */
final readonly class ScanAllLibrariesCommand
{
}
