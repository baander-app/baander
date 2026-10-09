<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/** The server cannot write a directory holding files a deletion would unlink; nothing was deleted. */
final class LibraryMediaDirectoryNotWritableException extends ConflictException
{
    /** @param non-empty-list<string> $directories */
    public static function forDirectories(array $directories): self
    {
        return new self(
            sprintf('The server cannot delete files in %s. Nothing was deleted.', implode(', ', $directories)),
            ['reason' => 'directory_not_writable', 'directories' => $directories],
        );
    }
}
