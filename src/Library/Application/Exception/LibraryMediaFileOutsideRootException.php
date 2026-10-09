<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\InvalidInputException;

/** A file deletion named a path that, or whose symlink target, lies outside its library's root; nothing was deleted. */
final class LibraryMediaFileOutsideRootException extends InvalidInputException
{
    private const int NAMED = 3;

    /** @param non-empty-list<string> $paths */
    public static function forPaths(string $libraryName, array $paths): self
    {
        $named = implode(', ', array_slice($paths, 0, self::NAMED));
        $more = count($paths) > self::NAMED ? sprintf(' and %d more', count($paths) - self::NAMED) : '';

        return new self(
            sprintf('These files lie outside the root of the library "%s": %s%s. Nothing was deleted.', $libraryName, $named, $more),
            ['reason' => 'path_outside_library', 'paths' => $paths],
        );
    }
}
