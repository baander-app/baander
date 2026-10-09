<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Exception\LiveConnectionsNotClosedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Application\Port\TransactionPortInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Disables an account and ends every session it has.
 *
 * The user's access and refresh tokens are revoked in the transaction that saves the
 * disable, so a failed revocation leaves the account enabled. After the commit the
 * web server closes the user's open WebSocket connections; closing them earlier
 * would let a reconnect pass on a token the commit has not yet revoked. Enabling the
 * account later restores none of the tokens; the user signs in again. Disabling a
 * disabled account leaves it unchanged and closes any connection still open, which
 * is how an operator retries a close that failed.
 */
final readonly class DisableUserHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private TransactionPortInterface $transaction,
        private LiveConnectionsPortInterface $liveConnections,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return User the disabled user
     *
     * @throws UserNotFoundException when no user has the email address or UUID
     * @throws LiveConnectionsNotClosedException when the disable committed but the web server did not close the connections
     */
    #[AsMessageHandler]
    public function __invoke(DisableUserCommand $command): User
    {
        $user = $this->userLookup->byIdentifier($command->getIdentifier());
        if (!$user->isDisabled()) {
            $this->transaction->run(function () use ($user): void {
                $user->disable();
                $this->userRepository->save($user);
                $this->accessTokenRepository->revokeForUser($user->getId());
                $this->refreshTokenRepository->revokeForUser($user->getId());
            });
        }

        $this->closeLiveConnections($user, $command->getIdentifier());

        return $user;
    }

    private function closeLiveConnections(User $user, string $identifier): void
    {
        try {
            $this->liveConnections->closeForUser($user->getId());
        } catch (ServerNotRunningException) {
            // Only reached outside the web server: a console command in a container
            // without one, so the server's connections are not reachable from here.
            $this->logger->warning(
                'No web server runs in this container, so the open WebSocket connections of user "{user}" were not closed. '
                . 'Run app:user:disable in the web container to close them.',
                ['user' => $identifier],
            );
        } catch (ServerControlException $exception) {
            throw LiveConnectionsNotClosedException::forUser($identifier, $exception);
        }
    }
}
