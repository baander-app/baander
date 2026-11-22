<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\HashedPassword;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Sets a user's password and ends what the old password granted.
 *
 * Every password change goes through here: redeeming a reset token, an operator reset and
 * the user's own change. The caller hashes the new password first (hash()), outside any
 * transaction, and passes it to change().
 *
 * change() first removes any outstanding reset token, in the order a reset redemption takes
 * that row and then the user's, so a change racing a redemption waits instead of deadlocking.
 * CredentialChangeRevoker then locks the user's row, refuses a change whose session another
 * change already ended, saves the new password, advances the credential epoch and revokes every
 * other session. Issuance and refresh lock that row and compare epochs, so a login or grant
 * whose credential was proved before the change either committed before it, and the
 * revocation catches its tokens, or waits for it and is refused. Saving the user also removes
 * any outstanding reset token (UserRepositoryInterface::save drops it when the password hash
 * changes). PasswordChanged is recorded in the same transaction; Notification turns it into a
 * security notice. The caller closes the revoked sessions' WebSocket connections once its
 * transaction has committed (CredentialChangeRevoker::closeConnections()).
 */
final readonly class PasswordChanger
{
    public function __construct(
        private PasswordHasherInterface $passwordHasher,
        private UserRepositoryInterface $userRepository,
        private CredentialChangeRevoker $credentialChangeRevoker,
        private TransactionPortInterface $transaction,
        private EventDispatcherInterface $eventDispatcher,
        private PasswordResetTokenRepositoryInterface $passwordResetTokens,
    ) {
    }

    /**
     * Checks the new password against the policy and hashes it. Call it before opening a
     * transaction: Argon2id is deliberately slow.
     *
     * @throws PasswordPolicyException when the password breaks PasswordPolicy
     */
    public function hash(string $plainPassword): HashedPassword
    {
        PasswordPolicy::assertAcceptable($plainPassword);

        return new HashedPassword($this->passwordHasher->hash($plainPassword));
    }

    /**
     * @param string|null $keptAccessTokenId the token id of the access token the change was
     *                                       requested with: that session stays signed in,
     *                                       moved to the new credential epoch. Null signs out
     *                                       every session.
     *
     * @throws CredentialsChangedException when the kept session was ended by another change first
     */
    #[\NoDiscard('Close the revoked sessions\' connections once the transaction has committed.')]
    public function change(User $user, HashedPassword $password, ?string $keptAccessTokenId = null): RevokedSessions
    {
        return $this->transaction->run(function () use ($user, $password, $keptAccessTokenId): RevokedSessions {
            $this->passwordResetTokens->revokeForUser($user->getId());

            return $this->credentialChangeRevoker->revokeSessions(
                $user,
                $keptAccessTokenId,
                fn () => $this->savePassword($user, $password),
            );
        });
    }

    private function savePassword(User $user, HashedPassword $password): void
    {
        $user->changePassword($password->hash);
        $this->userRepository->save($user);
        $this->eventDispatcher->dispatch(new PasswordChanged($user->getId(), Email::fromString($user->getEmail())));
    }
}
