<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

/** The verdict on one media file path. */
final readonly class LibraryMediaFileCheck
{
    /**
     * @param string      $path      the path as requested
     * @param string|null $directory the resolved directory the server cannot write, for DirectoryNotWritable
     */
    public function __construct(
        public string $path,
        public LibraryMediaFileVerdict $verdict,
        public ?string $directory = null,
    ) {
    }
}
