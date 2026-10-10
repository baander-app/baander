<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileInspection;

/**
 * What a delete with the audio files would do with each file. A delete is refused when a scan
 * holds the library, or when any file lies outside the library root or in a directory the server
 * cannot write.
 */
final readonly class FileDeletionPreview
{
    /**
     * @param list<array{path: string, verdict: string, directory: string|null}> $files one per distinct path;
     *                                                                                  verdict is deletable, missing, outside_root or directory_not_writable
     */
    public function __construct(
        public bool $allowed,
        public bool $scanInProgress,
        public array $files,
    ) {
    }

    public static function fromInspection(LibraryMediaFileInspection $inspection): self
    {
        return new self(
            $inspection->allowsDeletion(),
            $inspection->libraryBusy,
            array_map(
                static fn (LibraryMediaFileCheck $file): array => ['path' => $file->path, 'verdict' => $file->verdict->value, 'directory' => $file->directory],
                $inspection->files,
            ),
        );
    }
}
