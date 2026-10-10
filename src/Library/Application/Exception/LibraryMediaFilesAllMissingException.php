<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/**
 * Every file of a delete with files reads as missing, as when the storage under part of the
 * library is not mounted. Deleting their index rows would let the next scan import them again
 * once the storage is back, so nothing is deleted.
 */
final class LibraryMediaFilesAllMissingException extends ConflictException
{
    public static function underRoot(string $root): self
    {
        return new self(
            sprintf('None of the files exist in the library folder %s; its storage may not be mounted. Nothing was deleted.', $root),
            ['reason' => 'all_files_missing', 'root' => $root],
        );
    }
}
