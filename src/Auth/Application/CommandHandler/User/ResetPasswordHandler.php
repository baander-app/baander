<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ResetPasswordCommand;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Exception\PasswordResetException;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Redeems a password reset token and sets the new password, signing the account out of every
 * session (PasswordChanger).
 *
 * The reset went to the account's current address, so redeeming it proves control of that
 * address. It drops any pending email change, whose link would otherwise move the account
 * away from the address that just proved itself. On an address that was never verified, the
 * account may have been registered by someone else under it: the reset then verifies the
 * address and removes the account's passkeys and TOTP as well.
 *
 * The user is loaded under its row's FOR NO KEY UPDATE lock (lockAndFind()), which an
 * operator's disable or role change also waits on, and PasswordChanger saves these changes
 * with the new password. An account disabled before the lock was granted is refused as any
 * disabled account is, and no save writes back state read before the lock.
 *
 * The new password is checked against the policy and hashed before the transaction opens,
 * so a password the policy refuses leaves the token redeemable; redemption and the change
 * then run in one transaction, so nothing is half-claimed. The ended sessions' WebSocket
 * connections close once that transaction has committed. Redemptions are limited per IP,
 * which bounds the hashing an invalid token costs.
 */
final readonly class ResetPasswordHandler
{
    public function __construct(
        private PasswordResetTokenRepositoryInterface $passwordResetTokenRepository,
        private UserRepositoryInterface $userRepository,
        private PasswordChanger $passwordChanger,
        private CredentialChangeRevoker $credentialChangeRevoker,
        private PasskeyRepositoryInterface $passkeyRepository,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws PasswordResetException  for an unknown, expired or used token, or a disabled account
     * @throws PasswordPolicyException when the new password breaks the password policy
     */
    #[AsMessageHandler]
    public function __invoke(ResetPasswordCommand $command): void
    {
        $password = $this->passwordChanger->hash($command->password);

        $sessions = $this->transaction->run(function () use ($command, $password): ?RevokedSessions {
            // An unusable token is still deleted: the transaction commits and the caller is told
            // only that it is invalid.
            $userId = $this->passwordResetTokenRepository->redeem($command->token, new \DateTimeImmutable());
            $user = $userId === null ? null : $this->userRepository->lockAndFind($userId);

            if ($user === null || $user->isDisabled()) {
                return null;
            }

            $user->isEmailVerified() ? $user->discardPendingEmail() : $this->claim($user);

            return $this->passwordChanger->change($user, $password);
        });

        if ($sessions === null) {
            throw PasswordResetException::invalidToken();
        }
        $this->credentialChangeRevoker->closeConnections($sessions);
    }

    /** Verifies the address and removes the authenticators; PasswordChanger saves the user. */
    private function claim(User $user): void
    {
        $user->claimByPasswordReset();
        $this->userRepository->clearTotpStep($user->getId());
        foreach ($this->passkeyRepository->forUser($user->getId()) as $passkey) {
            $this->passkeyRepository->remove($passkey);
        }
    }
}
