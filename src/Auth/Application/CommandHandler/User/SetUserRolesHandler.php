<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Replaces a user's roles with the given set. Setting the roles the user already has
 * succeeds without change.
 */
final readonly class SetUserRolesHandler
{
    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @return User the user with the new roles
     *
     * @throws UserNotFoundException when no user has the email address or UUID
     * @throws InvalidInputException when no role is given or a role is unknown
     */
    #[AsMessageHandler]
    public function __invoke(SetUserRolesCommand $command): User
    {
        $user = $this->userLookup->byIdentifier($command->identifier);
        $before = $user->getRoles();

        try {
            $user->replaceRoles($command->roles);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), ['roles' => [$exception->getMessage()]], $exception);
        }

        if ($user->getRoles() !== $before) {
            $this->userRepository->save($user);
        }

        return $user;
    }
}
