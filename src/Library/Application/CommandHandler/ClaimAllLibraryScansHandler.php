<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ClaimAllLibraryScansCommand;
use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\Service\LibraryScanClaims;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Claims every library that is not scanning, for the scans `app:library:scan --all` runs inline. */
final readonly class ClaimAllLibraryScansHandler
{
    public function __construct(
        private LibraryScanClaims $claims,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ClaimAllLibraryScansCommand $command): LibraryScanClaimResult
    {
        return $this->claims->claimAll();
    }
}
