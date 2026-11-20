<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use Baander\MediaMetadata\ExtractedMetadata;

interface MetadataContentReaderPortInterface
{
    public function readMetadata(string $path): ?ExtractedMetadata;
}
