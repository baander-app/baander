<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DeleteUserCommand;
use App\Auth\Application\Exception\LastSuperAdminException;
use App\Auth\Application\Exception\LiveConnectionsNotClosedException;
use App\Auth\Application\Exception\UserNotFoundException;
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
 * Deletes a user account, for the admin panel and `app:user:delete`, and ends its open
 * WebSocket connections.
 *
 * The deletion takes the user's tokens with it. After the commit the web server closes the
 * user's open connections; closing them earlier would let a reconnect pass on a token the
 * commit has not yet removed. The install's only enabled super admin is not deleted.
 */
final readonly class DeleteUserHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
        private TransactionPortInterface $transaction,
        private SuperAdminContinuity $superAdminContinuity,
        private LiveConnectionsPortInterface $liveConnections,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws UserNotFoundException when no user has the email address or public ID
     * @throws LastSuperAdminException when the user is the only enabled super admin
     * @throws LiveConnectionsNotClosedException when the deletion committed but the web server did not close the connections
     */
    #[AsMessageHandler]
    public function __invoke(DeleteUserCommand $command): void
    {
        $user = $this->userLookup->byIdentifier($command->identifier);

        $this->transaction->run(function () use ($user): void {
            $this->superAdminContinuity->assertAnotherRemains($user);
            $this->userRepository->delete($user->getId());
        });

        $this->closeLiveConnections($user, $command->identifier);
    }

    private function closeLiveConnections(User $user, string $identifier): void
    {
        try {
            $this->liveConnections->closeForUser($user->getId());
        } catch (ServerNotRunningException) {
            // Only reached outside the web server: a console command in a container
            // without one, so the server's connections are not reachable from here.
            $this->logger->warning(
                'No web server runs in this container, so the open WebSocket connections of the deleted user "{user}" were not closed. '
                . 'The web server closes them within a minute.',
                ['user' => $identifier],
            );
        } catch (ServerControlException $exception) {
            throw LiveConnectionsNotClosedException::forDeletedUser($identifier, $exception);
        }
    }
}
