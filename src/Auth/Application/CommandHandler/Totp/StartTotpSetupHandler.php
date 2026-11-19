<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Totp;

use App\Auth\Application\Command\Totp\StartTotpSetupCommand;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\PendingTotpSecrets;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts TOTP setup: refuses while TOTP is on, which only the current code can turn off,
 * and holds a new secret for the user until enable confirms it.
 */
final readonly class StartTotpSetupHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private TotpVerifierInterface $totpVerifier,
        private PendingTotpSecrets $pendingSecrets,
    ) {
    }

    /** @return string The base32-encoded secret for the authenticator app */
    #[AsMessageHandler]
    public function __invoke(StartTotpSetupCommand $command): string
    {
        $userId = Uuid::fromString($command->getUserId());
        $user = $this->userRepository->findByUuid($userId) ?? throw UserNotFoundException::forIdentifier($userId->toString());
        if ($user->hasTotp()) {
            throw new TotpChangeRefusedException(TotpChangeRefusal::AlreadyEnabled);
        }

        $secret = $this->totpVerifier->generateSecret();
        $this->pendingSecrets->hold($userId, $secret);

        return $secret;
    }
}
