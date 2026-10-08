<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanAllLibrariesCommand;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\Service\LibraryScanClaims;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/** Claims every library that is not scanning and queues a scan for each, for the admin panel. */
final readonly class ScanAllLibrariesHandler
{
    public function __construct(
        private LibraryScanClaims $claims,
        private MessageBusInterface $bus,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ScanAllLibrariesCommand $command): LibraryScanClaimResult
    {
        $result = $this->claims->claimAll();

        foreach ($result->claimed as $index => $library) {
            try {
                $this->bus->dispatch(new ScanLibraryCommand($library->getSlug()));
            } catch (Throwable $exception) {
                // Scans that were never queued must not hold their claims.
                foreach (array_slice($result->claimed, $index) as $unqueued) {
                    $this->claims->release($unqueued->getId()->toString());
                }
                throw $exception;
            }
        }

        return $result;
    }
}
