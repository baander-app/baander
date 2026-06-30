<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Infrastructure\Scanner\MediaFile;

interface DirectoryScannerPortInterface
{
    /**
     * @return MediaFile[]
     */
    public function scan(LibraryPath $path): array;
}
