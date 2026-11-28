<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Service\LibraryScanLease;
use App\Library\Domain\Model\Library;
use Closure;

final readonly class TvScanner
{
    public function __construct(private MovieScanner $videoScanner)
    {
    }

    /** @param (Closure(string, array<DiscoveredFile>): void)|null $publishDirectory */
    public function scan(Library $library, bool $rescan = false, ?Closure $publishDirectory = null, ?LibraryScanLease $lease = null): ScanResult
    {
        return $this->videoScanner->scan($library, $rescan, $publishDirectory, $lease);
    }
}
