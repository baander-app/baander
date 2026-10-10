<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\DTO\LibraryScanSummary;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryBusyException;
use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Application\LibraryDiscovery;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Service\LibraryScanClaims;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/** Runs a library scan under the claim its sender took, publishing each discovered directory. */
final class ScanLibraryHandler
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraryRepository,
        private readonly LibraryScanClaims $claims,
        private readonly LibraryDiscovery $discovery,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws LibraryNotFoundException
     * @throws LibraryBusyException when another scan holds a live claim on the library
     * @throws LibraryScanClaimLostException      when another scan took the claim over during the scan
     */
    #[AsMessageHandler]
    public function __invoke(ScanLibraryCommand $command): LibraryScanSummary
    {
        $claimId = $command->getClaimId() ?? new Uuid();
        $library = $this->library($command, $claimId);
        $lease = $this->claims->acquire($library, $claimId);

        // Emit FilesDiscovered per directory via Messenger
        $queued = 0;
        $result = $this->discovery->discover($library, $command->isRescan(), $lease, function (string $directory, array $files) use ($library, &$queued): void {
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

    /** @throws LibraryNotFoundException */
    private function library(ScanLibraryCommand $command, Uuid $claimId): Library
    {
        try {
            return $this->libraryRepository->findBySlug($command->getLibrarySlug())
                ?? throw LibraryNotFoundException::forIdentifier($command->getLibrarySlug()->toString());
        } catch (Throwable $exception) {
            // The scan cannot start, so the sender's claim must not wait for its lease to lapse.
            try {
                $this->claims->end($claimId);
            } catch (Throwable $endFailure) {
                $this->logger->error('The claim of a library scan that could not start was not ended; it lapses with its lease', [
                    'library_slug' => $command->getLibrarySlug()->toString(),
                    'claim_id' => $claimId->toString(),
                    'error' => $endFailure->getMessage(),
                ]);
            }

            throw $exception;
        }
    }
}
