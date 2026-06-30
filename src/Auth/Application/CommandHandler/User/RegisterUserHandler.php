<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Event\UserRegistered;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Notification\Application\DTO\SeedDefaultPreferencesCommand;
use App\Shared\Domain\Model\Email;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class RegisterUserHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RegisterUserCommand $command): User
    {
        if ($this->userRepository->existsWithEmail($command->getEmail())) {
            throw new \RuntimeException('A user with this email already exists.');
        }

        $hashedPassword = $this->passwordHasher->hash($command->getPlainPassword());
        $user = User::register($command->getEmail(), $hashedPassword, $command->getName());

        $this->userRepository->save($user);

        $this->bus->dispatch(new SeedDefaultPreferencesCommand(
            userId: $user->getId(),
        ));

        $this->eventDispatcher->dispatch(new UserRegistered(
            userId: $user->getId(),
            publicId: $user->getPublicId(),
            email: Email::fromString($user->getEmail()),
            name: $user->getName(),
        ));

        return $user;
    }
}
