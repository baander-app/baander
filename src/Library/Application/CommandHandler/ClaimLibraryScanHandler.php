<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\ClaimLibraryScanCommand;
use App\Library\Application\DTO\LibraryScanClaim;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryBusyException;
use App\Library\Application\Service\LibraryScanClaims;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Claims a library for a scan that `app:library:scan` runs inline. */
final readonly class ClaimLibraryScanHandler
{
    public function __construct(
        private LibraryScanClaims $claims,
    ) {
    }

    /**
     * @throws LibraryNotFoundException
     * @throws LibraryBusyException
     */
    #[AsMessageHandler]
    public function __invoke(ClaimLibraryScanCommand $command): LibraryScanClaim
    {
        return $this->claims->claim($command->library);
    }
}
