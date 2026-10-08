<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Command\StartLibraryScanCommand;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryScanAlreadyRunningException;
use App\Library\Application\Service\LibraryScanClaims;
use App\Library\Domain\Model\Library;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/** Claims a library for a scan and queues the scan, for the admin panel. */
final readonly class StartLibraryScanHandler
{
    public function __construct(
        private LibraryScanClaims $claims,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @throws LibraryNotFoundException
     * @throws LibraryScanAlreadyRunningException
     */
    #[AsMessageHandler]
    public function __invoke(StartLibraryScanCommand $command): Library
    {
        $library = $this->claims->claim($command->library);

        try {
            $this->bus->dispatch(new ScanLibraryCommand($library->getSlug(), $command->rescan));
        } catch (Throwable $exception) {
            // A scan that was never queued must not hold the claim.
            $this->claims->release($library->getId()->toString());
            throw $exception;
        }

        return $library;
    }
}
