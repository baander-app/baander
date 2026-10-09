<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/**
 * The library's root is not an existing directory, such as unmounted storage. Every file would
 * read as missing, and deleting their index rows would let the next scan import them again.
 */
final class LibraryRootUnavailableException extends ConflictException
{
    public static function forRoot(string $root): self
    {
        return new self(
            sprintf('The library folder %s is not available, so its files cannot be checked. Nothing was deleted.', $root),
            ['reason' => 'library_root_unavailable', 'root' => $root],
        );
    }
}
