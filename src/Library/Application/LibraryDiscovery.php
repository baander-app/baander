<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Service\LibraryScanLease;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\JobCancelledException;
use Closure;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Runs one library scan under its claim: file-index maintenance through the scanners, which
 * renew the claim as they go, and the completion event. Callers decide what happens to each
 * discovered directory; the scanners publish each directory as they finish it, before the scan
 * ends its claim as completed, so a publication failure marks the scan failed. A scan whose job
 * is cancelled stops before its next directory and is marked failed too, which ends its claim;
 * the directories it published stay published. A scan that lost its claim stops without
 * touching the claim that replaced it.
 */
final class LibraryDiscovery
{
    public function __construct(
        private readonly MusicScanner $musicScanner,
        private readonly MovieScanner $movieScanner,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param LibraryScanLease                                    $lease            the claim the scan holds, from LibraryScanClaims::acquire()
     * @param (Closure(string, array<DiscoveredFile>): void)|null $publishDirectory
     *
     * @throws JobCancelledException        when the job running the scan was cancelled
     * @throws LibraryScanClaimLostException when another scan took the claim over
     */
    public function discover(Library $library, bool $rescan, LibraryScanLease $lease, ?Closure $publishDirectory = null): ScanResult
    {
        try {
            $result = match ($library->getType()) {
                LibraryType::Music => $this->musicScanner->scan($library, $rescan, $publishDirectory, $lease),
                LibraryType::Movie => $this->movieScanner->scan($library, $rescan, $publishDirectory, $lease),
                default => throw new RuntimeException(sprintf('Unsupported library type: %s', $library->getType()->value)),
            };

            $lease->complete();

            $this->eventDispatcher->dispatch(new LibraryScanCompleted(
                libraryId: $library->getId(),
                filesDiscovered: $result->filesDiscovered,
                filesProcessed: $result->filesProcessed,
            ));
        } catch (Throwable $e) {
            $this->fail($library, $lease);

            $context = [
                'library_id' => $library->getId()->toString(),
                'library_name' => $library->getName(),
            ];
            match (true) {
                $e instanceof JobCancelledException => $this->logger->info('Library scan cancelled', $context),
                $e instanceof LibraryScanClaimLostException => $this->logger->warning('Library scan stopped: it lost its claim', $context),
                default => $this->logger->error('Library scan failed', $context + ['error' => $e->getMessage()]),
            };

            throw $e;
        }

        return $result;
    }

    /** Ends the claim as failed; a claim another scan holds now stays as it is. */
    private function fail(Library $library, LibraryScanLease $lease): void
    {
        try {
            $lease->fail();
        } catch (Throwable $failure) {
            $this->logger->error('The claim of a failed library scan was not ended; it lapses with its lease', [
                'library_id' => $library->getId()->toString(),
                'claim_id' => $lease->claimId()->toString(),
                'error' => $failure->getMessage(),
            ]);
        }
    }
}
