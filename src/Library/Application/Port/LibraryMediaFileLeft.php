<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

/** A file a deletion left on disk; the next incremental scan imports it again. */
final readonly class LibraryMediaFileLeft
{
    /** @param string $detail what happened, in English, such as the file system's error */
    public function __construct(
        public string $path,
        public LibraryMediaFileLeftReason $reason,
        public string $detail,
    ) {
    }
}
