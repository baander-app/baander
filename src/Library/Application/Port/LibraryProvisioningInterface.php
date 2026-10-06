<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

/**
 * Worker-free library provisioning for developer ingest tools.
 *
 * Each call runs a synchronous scan and then returns every discovered directory
 * from a forced rescan, so the caller can process the files without Messenger
 * workers.
 */
interface LibraryProvisioningInterface
{
    /**
     * Finds the library by slug, or creates a local movie library at the path, and scans it.
     */
    public function provisionMovieLibrary(string $name, string $slug, string $path): ProvisionedLibraryScan;

    /**
     * Scans the library with this slug, or returns null when it does not exist.
     */
    public function scanExistingLibrary(string $slug): ?ProvisionedLibraryScan;
}
