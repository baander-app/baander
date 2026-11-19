<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Totp;

use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\TotpSecretCipherInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\PendingTotpSecrets;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns TOTP on with the secret the user's latest setup generated, once a code from it
 * matches. The secret is stored sealed, and the code's step is spent, so the code that
 * confirmed the setup cannot also sign in.
 */
final readonly class EnableTotpHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private TotpVerifierInterface $totpVerifier,
        private TotpSecretCipherInterface $cipher,
        private PendingTotpSecrets $pendingSecrets,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(EnableTotpCommand $command): void
    {
        $userId = Uuid::fromString($command->getUserId());
        $user = $this->userRepository->findByUuid($userId) ?? throw UserNotFoundException::forIdentifier($userId->toString());
        if ($user->hasTotp()) {
            throw new TotpChangeRefusedException(TotpChangeRefusal::AlreadyEnabled);
        }

        $secret = $this->pendingSecrets->find($userId) ?? throw new TotpChangeRefusedException(TotpChangeRefusal::SetupMissing);
        $step = $this->totpVerifier->matchStep($secret, $command->getCode())
            ?? throw new TotpChangeRefusedException(TotpChangeRefusal::InvalidCode);

        $user->enableTotp($this->cipher->seal($secret));
        $this->userRepository->save($user);
        $this->userRepository->spendTotpStep($userId, $step);
        $this->pendingSecrets->forget($userId);
    }
}
