<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

/** The checked media files of one library, and whether a scan holds it. */
final readonly class LibraryMediaFileInspection
{
    /**
     * @param string                     $root           the library root's real path the files were checked against
     * @param list<LibraryMediaFileCheck> $files          one per distinct requested path, in request order
     * @param bool                       $scanInProgress a scan holds a live claim on the library
     */
    public function __construct(
        public Uuid $libraryId,
        public string $root,
        public array $files,
        public bool $scanInProgress,
    ) {
    }

    public function allowsDeletion(): bool
    {
        if ($this->scanInProgress) {
            return false;
        }

        foreach ($this->files as $file) {
            if ($file->verdict->refusesDeletion()) {
                return false;
            }
        }

        return true;
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
