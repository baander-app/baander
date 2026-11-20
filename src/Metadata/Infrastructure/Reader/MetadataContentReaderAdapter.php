<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use Baander\MediaMetadata\Model\ExtractedMetadata;
use Baander\MediaMetadata\MetadataContentReader as PackageMetadataContentReader;

final class MetadataContentReaderAdapter implements MetadataContentReaderPortInterface
{
    public function __construct(private readonly PackageMetadataContentReader $reader)
    {
    }

    #[\Override]
    public function readMetadata(string $path): ?ExtractedMetadata
    {
        return $this->reader->readMetadata($path);
    }

    public function readAs(string $path, string $format): ?ExtractedMetadata
    {
        return $this->reader->readAs($path, $format);
    }
}
