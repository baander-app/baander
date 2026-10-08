<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\EnableUserCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Enables a disabled account. Enabling an enabled account succeeds without change.
 *
 * Sessions revoked when the account was disabled stay revoked; the user signs in again.
 */
final readonly class EnableUserHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @return User the enabled user
     *
     * @throws UserNotFoundException when no user has the email address or UUID
     */
    #[AsMessageHandler]
    public function __invoke(EnableUserCommand $command): User
    {
        $user = $this->userLookup->byIdentifier($command->getIdentifier());
        if (!$user->isDisabled()) {
            return $user;
        }

        $user->enable();
        $this->userRepository->save($user);

        return $user;
    }
}
