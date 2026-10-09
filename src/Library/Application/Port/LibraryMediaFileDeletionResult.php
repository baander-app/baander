<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

/** What a deletion did with each file, by requested path. */
final readonly class LibraryMediaFileDeletionResult
{
    /**
     * @param list<string>               $removed unlinked
     * @param list<string>               $missing nothing was there to unlink
     * @param list<LibraryMediaFileLeft> $left    still on disk
     */
    public function __construct(
        public array $removed,
        public array $missing,
        public array $left,
    ) {
    }

    public function leftAny(): bool
    {
        return $this->left !== [];
    }
}
