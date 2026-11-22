<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Totp;

use App\Auth\Application\Command\Totp\StartTotpSetupCommand;
use App\Auth\Application\Exception\EmailUnverifiedException;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\PendingTotpSecrets;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts TOTP setup: refuses while TOTP is on, which only step-up with a current code turns off, and
 * for an unverified email address, confirms the current password, and holds a new secret for the user until enable confirms it.
 */
final readonly class StartTotpSetupHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private TotpVerifierInterface $totpVerifier,
        private PendingTotpSecrets $pendingSecrets,
        private StepUpVerifier $stepUp,
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
        // Before step-up, so an unverified account spends none of its attempts.
        EmailUnverifiedException::unlessVerified($user);
        $this->stepUp->verify($user, $command->getCurrentPassword(), null);

        $secret = $this->totpVerifier->generateSecret();
        $this->pendingSecrets->hold($userId, $secret);

        return $secret;
    }
}
