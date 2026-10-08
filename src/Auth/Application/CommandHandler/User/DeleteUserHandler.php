<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DeleteUserCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Deletes a user account, for the admin panel and `app:user:delete`. */
final readonly class DeleteUserHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /** @throws UserNotFoundException when no user has the email address or UUID */
    #[AsMessageHandler]
    public function __invoke(DeleteUserCommand $command): void
    {
        $user = $this->userLookup->byIdentifier($command->identifier);

        $this->userRepository->delete($user->getId());
    }
}
