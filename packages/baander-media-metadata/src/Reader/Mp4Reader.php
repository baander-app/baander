<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Reader;

use Baander\MediaMetadata\Model\ExtractedMetadata;
use Baander\MediaMetadata\Parser\Mp4Parser;
use Psr\Log\LoggerInterface;

final class Mp4Reader
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function read(string $path): ExtractedMetadata
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->logger->warning('MP4 file does not exist or is not readable', ['path' => $path]);

            return new ExtractedMetadata();
        }

        try {
            return (new Mp4Parser($path))->parse();
        } catch (\Throwable $error) {
            $this->logger->warning('Failed to parse MP4 file', ['path' => $path, 'error' => $error->getMessage()]);

            return new ExtractedMetadata();
        }
    }
}
