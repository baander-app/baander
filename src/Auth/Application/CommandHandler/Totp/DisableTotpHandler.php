<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\Totp;

use App\Auth\Application\Command\Totp\DisableTotpCommand;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\StepUpVerifier;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A signed-in user turns their own TOTP off with their current password and a current code.
 * The session that asked stays signed in and every other session ends, paired devices and
 * third-party apps included, their WebSocket connections closed once the change has committed.
 */
final readonly class DisableTotpHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private StepUpVerifier $stepUp,
        private CredentialChangeRevoker $credentialChangeRevoker,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws TotpChangeRefusedException  when TOTP is not on, before any step-up attempt is spent
     * @throws StepUpFailedException       when the password or the code does not match
     * @throws StepUpRateLimitedException  when the account has used up its step-up attempts
     * @throws CredentialsChangedException when another change ended the requesting session first
     */
    #[AsMessageHandler]
    public function __invoke(DisableTotpCommand $command): void
    {
        $userId = Uuid::fromString($command->getUserId());
        $user = $this->userRepository->findByUuid($userId) ?? throw UserNotFoundException::forIdentifier($userId->toString());
        if (!$user->hasTotp()) {
            throw new TotpChangeRefusedException(TotpChangeRefusal::NotEnabled);
        }
        $this->stepUp->verify($user, $command->getCurrentPassword(), $command->getTotpCode());

        $sessions = $this->transaction->run(fn (): RevokedSessions => $this->credentialChangeRevoker->revokeSessions(
            $user,
            $command->getCurrentAccessTokenId(),
            fn () => $this->turnOffTotp($user),
        ));
        $this->credentialChangeRevoker->closeConnections($sessions);
    }

    private function turnOffTotp(User $user): void
    {
        $user->disableTotp();
        $this->userRepository->save($user);
    }
}
