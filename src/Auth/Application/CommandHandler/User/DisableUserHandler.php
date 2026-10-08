<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Disables an account and ends every session it has.
 *
 * The user's access and refresh tokens are revoked in the transaction that saves the
 * disable, so a failed revocation leaves the account enabled. Enabling the account
 * later restores none of them; the user signs in again. Disabling a disabled account
 * succeeds without change.
 */
final readonly class DisableUserHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private TransactionPortInterface $transaction,
    ) {
    }

    /** @throws UserNotFoundException when no user has the email address or UUID */
    #[AsMessageHandler]
    public function __invoke(DisableUserCommand $command): void
    {
        $user = $this->userLookup->byIdentifier($command->getIdentifier());
        if ($user->isDisabled()) {
            return;
        }

        $this->transaction->run(function () use ($user): void {
            $user->disable();
            $this->userRepository->save($user);
            $this->accessTokenRepository->revokeForUser($user->getId());
            $this->refreshTokenRepository->revokeForUser($user->getId());
        });
    }
}
