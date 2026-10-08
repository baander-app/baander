<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\RenameUserCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Changes a user's display name. Renaming to the current name succeeds without change.
 *
 * The admin API's request validation uses these messages and limit as well, so both
 * paths reject the same names with the same message.
 */
final readonly class RenameUserHandler
{
    public const int NAME_MAX_LENGTH = 255;
    public const string BLANK_NAME = 'Name cannot be empty.';
    public const string NAME_TOO_LONG = 'Name cannot be longer than 255 characters.';

    public function __construct(
        private UserLookup $userLookup,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @return User the renamed user
     *
     * @throws UserNotFoundException when no user has the email address or UUID
     * @throws InvalidInputException when the name is blank or longer than NAME_MAX_LENGTH characters
     */
    #[AsMessageHandler]
    public function __invoke(RenameUserCommand $command): User
    {
        if (trim($command->name) === '') {
            throw new InvalidInputException(self::BLANK_NAME, ['name' => [self::BLANK_NAME]]);
        }
        if (mb_strlen($command->name) > self::NAME_MAX_LENGTH) {
            throw new InvalidInputException(self::NAME_TOO_LONG, ['name' => [self::NAME_TOO_LONG]]);
        }

        $user = $this->userLookup->byIdentifier($command->identifier);
        if ($user->getName() === $command->name) {
            return $user;
        }

        $user->updateName($command->name);
        $this->userRepository->save($user);

        return $user;
    }
}
