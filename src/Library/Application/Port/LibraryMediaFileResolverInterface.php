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
}
