<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ReleaseLibraryScanClaimCommand;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryScanClaimLiveException;
use App\Library\Application\Service\LibraryScanClaims;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ends a library's claim for an operator; a scan claim marks the scan failed, and a delete
 * claim leaves the scan status alone. Idempotent.
 */
final readonly class ReleaseLibraryScanClaimHandler
{
    public function __construct(
        private LibraryScanClaims $claims,
    ) {
    }

    /**
     * @return 'scan'|'delete'|null the kind of the claim released; null when none held the library
     *
     * @throws LibraryNotFoundException
     * @throws LibraryScanClaimLiveException when the claim is live and the command does not force it
     */
    #[AsMessageHandler]
    public function __invoke(ReleaseLibraryScanClaimCommand $command): ?string
    {
        return $this->claims->release($command->library, $command->force)?->value;
    }
}
