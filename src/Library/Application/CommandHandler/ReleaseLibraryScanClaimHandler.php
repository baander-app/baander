<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ReleaseLibraryScanClaimCommand;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryScanClaimLiveException;
use App\Library\Application\Service\LibraryScanClaims;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Ends a library's scan claim for an operator and marks the scan failed; idempotent. */
final readonly class ReleaseLibraryScanClaimHandler
{
    public function __construct(
        private LibraryScanClaims $claims,
    ) {
    }

    /**
     * @return bool whether a claim was released; false when no scan held one
     *
     * @throws LibraryNotFoundException
     * @throws LibraryScanClaimLiveException when the claim is live and the command does not force it
     */
    #[AsMessageHandler]
    public function __invoke(ReleaseLibraryScanClaimCommand $command): bool
    {
        return $this->claims->release($command->library, $command->force);
    }
}
