<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\LibraryDiscovery;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use RuntimeException;
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

    #[AsMessageHandler]
    public function __invoke(ScanLibraryCommand $command): Library
    {
        $library = $this->libraryRepository->findBySlug($command->getLibrarySlug());

        if ($library === null) {
            throw new RuntimeException(sprintf('Library with slug "%s" not found.', $command->getLibrarySlug()->toString()));
        }

        // Emit FilesDiscovered per directory via Messenger
        $this->discovery->discover($library, $command->isRescan(), function (string $directory, array $files) use ($library): void {
            $this->messageBus->dispatch(new FilesDiscovered(
                libraryId: $library->getId(),
                libraryType: $library->getType()->value,
                directory: $directory,
                files: $files,
            ));
        });

        return $library;
    }
}
