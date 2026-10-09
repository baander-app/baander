<?php

declare(strict_types=1);

namespace App\Library\Application\CommandHandler;

use App\Library\Application\Command\EndLibraryScanClaimCommand;
use App\Library\Application\Service\LibraryScanClaims;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Ends the claim of a scan that will not run to its end; idempotent. */
final readonly class EndLibraryScanClaimHandler
{
    public function __construct(
        private LibraryScanClaims $claims,
    ) {
    }

    /**
     * @return bool false when the claim had already ended or another scan took it over
     *
     * @throws \InvalidArgumentException when the claim ID is not a UUID
     */
    #[AsMessageHandler]
    public function __invoke(EndLibraryScanClaimCommand $command): bool
    {
        return $this->claims->end(Uuid::fromString($command->claimId));
    }
}
