<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\DTO\LibraryScanSummary;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\LibraryDiscovery;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class ScanLibraryHandler
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraryRepository,
        private readonly LibraryDiscovery $discovery,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    /** @throws LibraryNotFoundException */
    #[AsMessageHandler]
    public function __invoke(ScanLibraryCommand $command): LibraryScanSummary
    {
        $library = $this->libraryRepository->findBySlug($command->getLibrarySlug())
            ?? throw LibraryNotFoundException::forIdentifier($command->getLibrarySlug()->toString());

        // Emit FilesDiscovered per directory via Messenger
        $queued = 0;
        $result = $this->discovery->discover($library, $command->isRescan(), function (string $directory, array $files) use ($library, &$queued): void {
            $this->messageBus->dispatch(new FilesDiscovered(
                libraryId: $library->getId(),
                libraryType: $library->getType()->value,
                directory: $directory,
                files: $files,
            ));
            $queued++;
        });

        return new LibraryScanSummary(
            libraryId: $library->getId()->toString(),
            name: $library->getName(),
            slug: $library->getSlug()->toString(),
            filesDiscovered: $result->filesDiscovered,
            filesProcessed: $result->filesProcessed,
            filesSkipped: $result->filesSkipped,
            directoriesQueued: $queued,
        );
    }
}
