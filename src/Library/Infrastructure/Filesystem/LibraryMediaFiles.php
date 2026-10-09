<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Filesystem;

use App\Library\Application\Exception\LibraryMediaDirectoryNotWritableException;
use App\Library\Application\Exception\LibraryMediaFileOutsideRootException;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryRootUnavailableException;
use App\Library\Application\Exception\LibraryScanAlreadyRunningException;
use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileDeletionResult;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

final readonly class LibraryMediaFiles implements LibraryMediaFilesInterface
{
    public function __construct(
        private LibraryRepositoryInterface $libraries,
        private LibraryFileIndexRepositoryInterface $fileIndex,
        private MediaFileGuard $guard,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function inspect(Uuid $libraryId, array $paths): LibraryMediaFileInspection
    {
        return $this->inspectLibrary($this->library($libraryId), $paths);
    }

    public function prepareDeletion(Uuid $libraryId, array $paths): LibraryMediaFileInspection
    {
        $library = $this->library($libraryId);
        $inspection = $this->inspectLibrary($library, $paths);

        if ($inspection->scanInProgress) {
            throw LibraryScanAlreadyRunningException::forLibrary($library->getName());
        }

        if (!$inspection->rootAvailable) {
            throw LibraryRootUnavailableException::forRoot($library->getPath()->toString());
        }

        $outside = $inspection->withVerdict(LibraryMediaFileVerdict::OutsideRoot);
        if ($outside !== []) {
            throw LibraryMediaFileOutsideRootException::forPaths(
                $library->getName(),
                array_map(static fn (LibraryMediaFileCheck $file): string => $file->path, $outside),
            );
        }

        $unwritable = $inspection->withVerdict(LibraryMediaFileVerdict::DirectoryNotWritable);
        if ($unwritable !== []) {
            throw LibraryMediaDirectoryNotWritableException::forDirectories(array_values(array_unique(
                array_map(static fn (LibraryMediaFileCheck $file): string => (string) $file->directory, $unwritable),
            )));
        }

        return $inspection;
    }

    public function deleteIndexRows(LibraryMediaFileInspection $deletion): void
    {
        // Outside the caller's transaction the rows would go even when its catalog delete fails.
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new LogicException('Delete the file index rows inside the transaction that deletes the catalog rows.');
        }

        $this->fileIndex->removeByPaths($deletion->libraryId, $deletion->paths());
    }

    public function deleteFiles(LibraryMediaFileInspection $deletion): LibraryMediaFileDeletionResult
    {
        return $this->guard->delete($deletion);
    }

    /** @param list<string> $paths */
    private function inspectLibrary(Library $library, array $paths): LibraryMediaFileInspection
    {
        return $this->guard->inspect(
            $library->getId(),
            $library->getPath()->toString(),
            $paths,
            $this->libraries->hasLiveScanClaim($library->getId()),
        );
    }

    private function library(Uuid $libraryId): Library
    {
        return $this->libraries->findByUuid($libraryId)
            ?? throw LibraryNotFoundException::forIdentifier($libraryId->toString());
    }
}
