<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Library\Domain\ValueObject\LibraryPath;
use Closure;
use App\Library\Application\DTO\MediaFile;

interface DirectoryScannerPortInterface
{
    /**
     * @param (Closure(): void)|null $checkpoint Called for each visited entry, including directories.
     * @return MediaFile[]
     */
    public function scan(LibraryPath $path, ?Closure $checkpoint = null, bool $unreadableRootIsEmpty = false): array;
}
