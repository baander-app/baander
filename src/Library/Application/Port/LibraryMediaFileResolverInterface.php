<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface LibraryMediaFileResolverInterface
{
    /**
     * Resolves an absolute catalog path to an existing file inside its owning library.
     *
     * @throws \InvalidArgumentException when the library or file is unavailable or the path escapes its root
     */
    public function resolve(Uuid $libraryId, string $absolutePath): string;

    /**
     * Resolves an absolute path to the real path of an existing file inside any library's root.
     * A symlink counts where its target lies, and a path that is not absolute, such as a URL, is
     * refused before any filesystem call.
     *
     * @throws \InvalidArgumentException when no library holds an existing file at the path
     */
    public function resolveInAnyLibrary(string $absolutePath): string;
}
