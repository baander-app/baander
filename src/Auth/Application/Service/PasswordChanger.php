<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

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
 * the user's own change. Saving the user also removes any outstanding reset token
 * (UserRepositoryInterface::save drops it when the password hash changes). PasswordChanged
 * is recorded in the same transaction; Notification turns it into a security notice.
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
     * @param AccessToken|null $keep the session to leave signed in: this access token and its
     *                               refresh chain. Null signs out every session.
     *
     * @throws PasswordPolicyException when the password breaks PasswordPolicy
     */
    public function change(User $user, string $plainPassword, ?AccessToken $keep = null): void
    {
        PasswordPolicy::assertAcceptable($plainPassword);

        // Hash before opening the transaction; Argon2id is deliberately slow.
        $hashedPassword = $this->passwordHasher->hash($plainPassword);

        $this->transaction->run(function () use ($user, $hashedPassword, $keep): void {
            $user->changePassword($hashedPassword);
            $this->userRepository->save($user);
            $this->accessTokenRepository->revokeForUser($user->getId(), $keep);
            $this->refreshTokenRepository->revokeForUser($user->getId(), $keep);
            $this->eventDispatcher->dispatch(new PasswordChanged($user->getId(), Email::fromString($user->getEmail())));
        });
    }
}
