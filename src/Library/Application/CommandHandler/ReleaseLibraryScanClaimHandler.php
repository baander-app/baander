<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ReleaseLibraryScanClaimCommand;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Service\LibraryScanClaims;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Ends a library's scan claim and marks the scan failed; idempotent. */
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
     */
    #[AsMessageHandler]
    public function __invoke(ReleaseLibraryScanClaimCommand $command): bool
    {
        return $this->claims->release($command->library);
    }
}
