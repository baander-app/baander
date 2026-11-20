<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ResetUserTotpCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns off a user's TOTP, forgets its last spent time step, advances the credential epoch
 * and ends every session the user has, in one transaction, so the user signs in again with
 * their password alone. A TOTP reset is a credential change: a login that checked the
 * password and code before the reset and waits on the account's row is refused, as after a
 * password change. The stored secret is dropped unread: a secret the configured key cannot
 * open is the case this reset exists for.
 */
final readonly class ResetUserTotpHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @return User the user without TOTP
     *
     * @throws UserNotFoundException when no user has the email address or public ID
     * @throws ConflictException     when the user has no TOTP to reset
     */
    #[AsMessageHandler]
    public function __invoke(ResetUserTotpCommand $command): User
    {
        $user = $this->userLookup->byIdentifier($command->identifier);
        if (!$user->hasTotp()) {
            throw new ConflictException(
                sprintf('TOTP is not on for "%s"; there is nothing to reset.', $command->identifier),
                ['reason' => 'totp_not_enabled'],
            );
        }

        $this->transaction->run(function () use ($user): void {
            $this->userRepository->advanceCredentialEpoch($user->getId());
            $user->disableTotp();
            $this->userRepository->save($user);
            $this->userRepository->clearTotpStep($user->getId());
            $this->accessTokenRepository->revokeForUser($user->getId());
            $this->refreshTokenRepository->revokeForUser($user->getId());
        });

        return $user;
    }
}
