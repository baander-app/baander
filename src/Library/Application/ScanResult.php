<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Domain\Model\DiscoveredFile;

final readonly class ScanResult
{
    /**
     * @param array<string, array<DiscoveredFile>> $directories directory path => discovered files
     */
    public function __construct(
        public readonly int $filesDiscovered,
        public readonly int $filesProcessed,
        public readonly int $filesSkipped,
        public readonly array $directories = [],
    ) {
    }
}
