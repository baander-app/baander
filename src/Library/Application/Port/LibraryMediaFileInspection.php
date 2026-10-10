<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

/** The checked media files of one library, and whether a scan or another delete holds it. */
final readonly class LibraryMediaFileInspection
{
    /**
     * @param string                     $root           the library root's real path the files were checked against
     * @param list<LibraryMediaFileCheck> $files          one per distinct requested path, in request order
     * @param bool                       $libraryBusy    a scan or another delete with files holds a live claim on the library
     * @param bool                       $rootAvailable  the library root is an existing directory; when it is not
     *                                                   (unmounted storage), every file reads as missing
     *
     * A deletion is refused when a holder is busy, the root is unavailable, every requested file
     * is missing, or any file lies outside the root or in a directory the server cannot write.
     */
    public function __construct(
        public Uuid $libraryId,
        public string $root,
        public array $files,
        public bool $libraryBusy,
        public bool $rootAvailable,
    ) {
    }

    public function allowsDeletion(): bool
    {
        if ($this->libraryBusy || !$this->rootAvailable || $this->allFilesMissing()) {
            return false;
        }

        foreach ($this->files as $file) {
            if ($file->verdict->refusesDeletion()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every requested file reads as missing, as when the storage under part of the library is not
     * mounted. False for a request without files, such as an album without songs.
     */
    public function allFilesMissing(): bool
    {
        return $this->files !== [] && count($this->withVerdict(LibraryMediaFileVerdict::Missing)) === count($this->files);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (LibraryMediaFileCheck $file): string => $file->path, $this->files);
    }

    /** @return list<LibraryMediaFileCheck> */
    public function withVerdict(LibraryMediaFileVerdict $verdict): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (LibraryMediaFileCheck $file): bool => $file->verdict === $verdict,
        ));
    }
}
