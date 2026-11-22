<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Application\Service\UserLookup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An operator sets a user's password. Every session of the user ends, its WebSocket
 * connections closed once the change has committed.
 */
final readonly class SetUserPasswordHandler
{
    public function __construct(
        private UserLookup $users,
        private PasswordChanger $passwordChanger,
        private CredentialChangeRevoker $credentialChangeRevoker,
    ) {
    }

    /**
     * @throws UserNotFoundException when no user has the email address or public ID
     */
    #[AsMessageHandler]
    public function __invoke(SetUserPasswordCommand $command): void
    {
        $user = $this->users->byIdentifier($command->identifier);
        $this->passwordChanger->change($user, $this->passwordChanger->hash($command->password))
            |> $this->credentialChangeRevoker->closeConnections(...);
    }
}
