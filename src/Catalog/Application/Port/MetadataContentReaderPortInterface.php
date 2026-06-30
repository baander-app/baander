<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Metadata\Domain\Model\ExtractedMetadata;

interface MetadataContentReaderPortInterface
{
    public function readMetadata(string $path): ?ExtractedMetadata;
}
