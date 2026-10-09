<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Library\Application\Port\LibraryMediaFileDeletionResult;
use App\Library\Application\Port\LibraryMediaFileLeft;

/**
 * What an album, song, movie or artist delete removed: the rows by kind and, when the delete
 * included the audio files, which files it unlinked, found missing or left on disk.
 */
final readonly class CatalogDeletionResult
{
    /**
     * @param array<string, int>                                        $deleted rows deleted by kind, such as `songs`
     * @param list<string>                                              $removed files unlinked
     * @param list<string>                                              $missing files that were already gone
     * @param list<array{path: string, reason: string, detail: string}> $left    files still on disk; the next scan imports them again
     */
    public function __construct(
        public array $deleted,
        public array $removed = [],
        public array $missing = [],
        public array $left = [],
    ) {
    }

    /** @param array<string, int> $deleted */
    public static function of(array $deleted, ?LibraryMediaFileDeletionResult $files = null): self
    {
        if ($files === null) {
            return new self($deleted);
        }

        return new self(
            $deleted,
            $files->removed,
            $files->missing,
            array_map(
                static fn (LibraryMediaFileLeft $file): array => ['path' => $file->path, 'reason' => $file->reason->value, 'detail' => $file->detail],
                $files->left,
            ),
        );
    }

    /** A requested file is still on disk. */
    public function leftAny(): bool
    {
        return $this->left !== [];
    }
}
