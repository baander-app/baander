<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Application\CommandHandler\CreateLibraryHandler;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryProvisioningInterface;
use App\Library\Application\Port\ProvisionedLibraryScan;
use App\Library\Application\Service\LibraryScanClaims;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;

final class LibraryProvisioner implements LibraryProvisioningInterface
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraryRepository,
        private readonly CreateLibraryHandler $createLibraryHandler,
        private readonly LibraryScanClaims $claims,
        private readonly LibraryDiscovery $discovery,
        private readonly MovieScanner $movieScanner,
    ) {
    }

    public function provisionMovieLibrary(string $name, string $slug, string $path): ProvisionedLibraryScan
    {
        $librarySlug = new LibrarySlug($slug);
        $created = false;

        if ($this->libraryRepository->findBySlug($librarySlug) === null) {
            ($this->createLibraryHandler)(new CreateLibraryCommand(
                name: $name,
                path: $path,
                type: LibraryType::Movie->value,
                filesystemType: FilesystemType::Local->value,
                slug: $librarySlug->toString(),
            ));
            $created = true;
        }

        return $this->scan($librarySlug, $created);
    }

    public function scanExistingLibrary(string $slug): ?ProvisionedLibraryScan
    {
        $librarySlug = new LibrarySlug($slug);
        if ($this->libraryRepository->findBySlug($librarySlug) === null) {
            return null;
        }

        return $this->scan($librarySlug, false);
    }

    private function scan(LibrarySlug $slug, bool $created): ProvisionedLibraryScan
    {
        $library = $this->libraryRepository->findBySlug($slug)
            ?? throw new \RuntimeException(sprintf('Library with slug "%s" not found.', $slug->toString()));

        // The regular scan, under its own claim, keeps the library status, file index
        // and scan event current without publishing FilesDiscovered, so workers never
        // ingest the same files. A forced rescan then collects every directory,
        // including unchanged files, for synchronous handling.
        $this->discovery->discover($library, false, $this->claims->acquire($library, new Uuid()));
        $scanResult = $this->movieScanner->scan($library, true);

        $discoveries = [];
        foreach ($scanResult->directories as $directory => $files) {
            $discoveries[] = new FilesDiscovered(
                libraryId: $library->getId(),
                libraryType: $library->getType()->value,
                directory: (string) $directory,
                files: $files,
            );
        }

        return new ProvisionedLibraryScan(
            libraryId: $library->getId(),
            libraryName: $library->getName(),
            created: $created,
            discoveries: $discoveries,
        );
    }
}
