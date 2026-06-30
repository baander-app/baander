<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\CreateUserCommand;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Event\UserCreatedByOperator;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Notification\Application\DTO\SeedDefaultPreferencesCommand;
use App\Shared\Domain\Model\Email;
use InvalidArgumentException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class CreateUserHandler
{
    private const ALLOWED_ROLES = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(CreateUserCommand $command): User
    {
        foreach ($command->getRoles() as $role) {
            if (!in_array($role, self::ALLOWED_ROLES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid role "%s". Allowed roles: %s',
                    $role,
                    implode(', ', self::ALLOWED_ROLES),
                ));
            }
        }

        if ($this->userRepository->existsWithEmail($command->getEmail())) {
            throw new \RuntimeException('A user with this email already exists.');
        }

        $hashedPassword = $this->passwordHasher->hash($command->getPlainPassword());
        $user = User::createByOperator(
            $command->getEmail(),
            $hashedPassword,
            $command->getName(),
            $command->getRoles(),
        );

        $this->userRepository->save($user);

        $this->bus->dispatch(new SeedDefaultPreferencesCommand(
            userId: $user->getId(),
        ));

        $this->eventDispatcher->dispatch(new UserCreatedByOperator(
            userId: $user->getId(),
            publicId: $user->getPublicId(),
            email: Email::fromString($user->getEmail()),
            name: $user->getName(),
            roles: $user->getRoles(),
        ));

        return $user;
    }
}
