<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangePasswordCommand;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\CurrentPasswordMismatchException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A signed-in user changes their own password. The session that asked stays signed in and
 * every other session ends, its WebSocket connections closed once the change has committed.
 */
final readonly class ChangePasswordHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PasswordHasherInterface $passwordHasher,
        private PasswordChanger $passwordChanger,
        private CredentialChangeRevoker $credentialChangeRevoker,
    ) {
    }

    /**
     * @throws CurrentPasswordMismatchException when the current password is wrong
     * @throws CredentialsChangedException      when another change ended the requesting session first
     */
    #[AsMessageHandler]
    public function __invoke(ChangePasswordCommand $command): void
    {
        $user = $this->userRepository->findByUuid(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forIdentifier($command->userId);

        if (!$this->passwordHasher->verify($command->currentPassword, $user->getPassword())) {
            throw CurrentPasswordMismatchException::create();
        }

        $password = $this->passwordChanger->hash($command->newPassword);
        $this->passwordChanger->change($user, $password, $command->currentAccessTokenId)
            |> $this->credentialChangeRevoker->closeConnections(...);
    }
}
