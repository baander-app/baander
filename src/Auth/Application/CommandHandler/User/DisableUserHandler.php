<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\LastSuperAdminException;
use App\Auth\Application\Exception\LiveConnectionsNotClosedException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\SuperAdminContinuity;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
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
 * The disable goes through CredentialChangeRevoker in one transaction: under the super admin
 * continuity lock and then the user row's lock, it saves the disable, advances the credential
 * epoch and revokes every access and refresh token, so a failed revocation leaves the account
 * enabled. The advanced epoch also refuses what was verified before the disable and issues
 * later: a sign-in waiting on the row, an unredeemed authorization code, an approved device
 * code. Enabling the account later restores none of it; the user signs in again.
 *
 * After the commit the web server closes the user's open WebSocket connections; closing them
 * earlier would let a reconnect pass on a token the commit has not yet revoked. Disabling a
 * disabled account leaves it unchanged and closes any connection still open, which is how an
 * operator retries a close that failed. The install's only enabled super admin is not disabled.
 */
final readonly class DisableUserHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
        private CredentialChangeRevoker $credentialChangeRevoker,
        private TransactionPortInterface $transaction,
        private SuperAdminContinuity $superAdminContinuity,
        private LiveConnectionsPortInterface $liveConnections,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return User the disabled user
     *
     * @throws UserNotFoundException when no user has the email address or public ID
     * @throws LastSuperAdminException when the user is the only enabled super admin
     * @throws LiveConnectionsNotClosedException when the disable committed but the web server did not close the connections
     */
    #[AsMessageHandler]
    public function __invoke(DisableUserCommand $command): User
    {
        $user = $this->userLookup->byIdentifier($command->getIdentifier());
        if (!$user->isDisabled()) {
            // The revoked token ids are not needed: the connections close by user below.
            $this->transaction->run(fn (): RevokedSessions => $this->endSessions($user));
        }

        $this->closeLiveConnections($user, $command->getIdentifier());

        return $user;
    }

    private function endSessions(User $user): RevokedSessions
    {
        $this->superAdminContinuity->assertAnotherRemains($user);

        return $this->credentialChangeRevoker->revokeSessions($user, null, function () use ($user): void {
            $user->disable();
            $this->userRepository->save($user);
        });
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
            throw LiveConnectionsNotClosedException::forDisabledUser($identifier, $exception);
        }
    }
}
