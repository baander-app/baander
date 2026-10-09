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

/**
 * Claims a library for a scan and queues the scan with the claim, for the admin panel. The claim
 * of a queued scan that never runs lapses after the lease.
 */
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
        $claim = $this->claims->claim($command->library);

        try {
            $this->bus->dispatch(new ScanLibraryCommand($claim->library->getSlug(), $command->rescan, $claim->claimId));
        } catch (Throwable $exception) {
            // A scan that was never queued must not hold the claim.
            $this->claims->end($claim->claimId);
            throw $exception;
        }

        return $claim->library;
    }
}
