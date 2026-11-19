<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\HashedPassword;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
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
 * change() first increments the user's credential epoch, which locks the user's row until
 * the transaction ends. Issuance and refresh lock that row and compare epochs, so a login
 * or grant whose credential was proved before the change either committed before it, and
 * the revocation below catches its tokens, or waits for it and is refused. Saving the user
 * also removes any outstanding reset token (UserRepositoryInterface::save drops it when the
 * password hash changes). PasswordChanged is recorded in the same transaction; Notification
 * turns it into a security notice.
 */
final readonly class PasswordChanger
{
    public function __construct(
        private PasswordHasherInterface $passwordHasher,
        private UserRepositoryInterface $userRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private TransactionPortInterface $transaction,
        private EventDispatcherInterface $eventDispatcher,
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
     * @param AccessToken|null $keep the session to leave signed in: this access token and its
     *                               refresh chain, moved to the new credential epoch. Null
     *                               signs out every session.
     */
    public function change(User $user, HashedPassword $password, ?AccessToken $keep = null): void
    {
        $this->transaction->run(function () use ($user, $password, $keep): void {
            $credentialEpoch = $this->userRepository->advanceCredentialEpoch($user->getId());
            $user->changePassword($password->hash);
            $this->userRepository->save($user);
            $this->accessTokenRepository->revokeForUser($user->getId(), $keep);
            $this->refreshTokenRepository->revokeForUser($user->getId(), $keep);
            if ($keep !== null) {
                $this->accessTokenRepository->restampCredentialEpoch($keep, $credentialEpoch);
                $this->refreshTokenRepository->restampCredentialEpoch($keep, $credentialEpoch);
            }
            $this->eventDispatcher->dispatch(new PasswordChanged($user->getId(), Email::fromString($user->getEmail())));
        });
    }
}
