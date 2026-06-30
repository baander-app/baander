<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\MovieScanner;
use App\Library\Application\MusicScanner;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryType;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final class ScanLibraryHandler
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraryRepository,
        private readonly MusicScanner $musicScanner,
        private readonly MovieScanner $movieScanner,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ScanLibraryCommand $command): Library
    {
        $library = $this->libraryRepository->findBySlug($command->getLibrarySlug());

        if ($library === null) {
            throw new RuntimeException(sprintf('Library with slug "%s" not found.', $command->getLibrarySlug()->toString()));
        }

        $library->markDiscoveryStarted();
        $this->libraryRepository->save($library);

        try {
            $result = match ($library->getType()) {
                LibraryType::Music => $this->musicScanner->scan($library, $command->isRescan()),
                LibraryType::Movie => $this->movieScanner->scan($library, $command->isRescan()),
                default => throw new RuntimeException(sprintf('Unsupported library type: %s', $library->getType()->value)),
            };

            // Emit FilesDiscovered per directory via Messenger
            foreach ($result->directories as $directory => $files) {
                $this->messageBus->dispatch(new FilesDiscovered(
                    libraryId: $library->getId(),
                    libraryType: $library->getType()->value,
                    directory: $directory,
                    files: $files,
                ));
            }

            $library->markDiscoveryCompleted();
            $this->libraryRepository->save($library);

            $this->eventDispatcher->dispatch(new LibraryScanCompleted(
                libraryId: $library->getId(),
                filesDiscovered: $result->filesDiscovered,
                filesProcessed: $result->filesProcessed,
            ));
        } catch (Throwable $e) {
            $library->markDiscoveryFailed();
            $this->libraryRepository->save($library);

            $this->logger->error('Library scan failed', [
                'library_id' => $library->getId()->toString(),
                'library_name' => $library->getName(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $library;
    }
}
