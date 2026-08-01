<?php

declare(strict_types=1);

namespace App\Filesystem\Application\Port;

interface MimeDetectorPortInterface
{
    /**
     * Detect the MIME type of a file by reading its magic bytes.
     */
    public function detect(string $path): string;
}
