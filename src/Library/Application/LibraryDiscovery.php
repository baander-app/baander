<?php

declare(strict_types=1);

namespace App\Library\Application;

use App\Library\Application\Message\DiscoveredFile;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\JobCancelledException;
use Closure;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Runs one library scan: discovery status, file-index maintenance through the
 * scanners, and the completion event. Callers decide what happens to each
 * discovered directory; the scanners publish each directory as they finish it,
 * before the scan is marked complete, so a publication failure marks the scan
 * failed. A scan whose job is cancelled stops before its next directory and is
 * marked failed too, which ends its claim; the directories it published stay
 * published.
 */
final class LibraryDiscovery
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraryRepository,
        private readonly MusicScanner $musicScanner,
        private readonly MovieScanner $movieScanner,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param (Closure(string, array<DiscoveredFile>): void)|null $publishDirectory
     *
     * @throws JobCancelledException when the job running the scan was cancelled
     */
    public function discover(Library $library, bool $rescan, ?Closure $publishDirectory = null): ScanResult
    {
        $library->markDiscoveryStarted();
        $this->libraryRepository->save($library);

        try {
            $result = match ($library->getType()) {
                LibraryType::Music => $this->musicScanner->scan($library, $rescan, $publishDirectory),
                LibraryType::Movie => $this->movieScanner->scan($library, $rescan, $publishDirectory),
                default => throw new RuntimeException(sprintf('Unsupported library type: %s', $library->getType()->value)),
            };

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

            if ($e instanceof JobCancelledException) {
                $this->logger->info('Library scan cancelled', [
                    'library_id' => $library->getId()->toString(),
                    'library_name' => $library->getName(),
                ]);
            } else {
                $this->logger->error('Library scan failed', [
                    'library_id' => $library->getId()->toString(),
                    'library_name' => $library->getName(),
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }

        return $result;
    }
}
