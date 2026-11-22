<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Totp;

use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\TotpSecretCipherInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\PendingTotpSecrets;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns TOTP on with the secret the user's latest setup generated, once a code from it
 * matches. The secret is stored sealed, and the code's step is spent in the same transaction,
 * so the code that confirmed the setup cannot also sign in.
 *
 * Turning TOTP on is a credential change: the session that sent the code stays signed in and
 * every other session ends, paired devices and third-party apps included, their WebSocket
 * connections closed once the change has committed. The pending secret is forgotten only then,
 * so a change that rolls back, such as one that timed out waiting for the account's row, can be
 * confirmed again without a new setup.
 */
final readonly class EnableTotpHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private TotpVerifierInterface $totpVerifier,
        private TotpSecretCipherInterface $cipher,
        private PendingTotpSecrets $pendingSecrets,
        private CredentialChangeRevoker $credentialChangeRevoker,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws TotpChangeRefusedException  when TOTP is already on, no setup is pending or the code does not match
     * @throws CredentialsChangedException when another change ended the requesting session first
     */
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
        $sealedSecret = $this->cipher->seal($secret);

        $sessions = $this->transaction->run(fn (): RevokedSessions => $this->credentialChangeRevoker->revokeSessions(
            $user,
            $command->getCurrentAccessTokenId(),
            fn () => $this->turnOnTotp($user, $sealedSecret, $step),
        ));
        $this->pendingSecrets->forget($userId);
        $this->credentialChangeRevoker->closeConnections($sessions);
    }

    private function turnOnTotp(User $user, string $sealedSecret, int $step): void
    {
        $user->enableTotp($sealedSecret);
        $this->userRepository->save($user);
        $this->userRepository->spendTotpStep($user->getId(), $step);
    }
}
