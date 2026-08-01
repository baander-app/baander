<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\LoginUserCommand;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class LoginUserHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(LoginUserCommand $command): User
    {
        $user = $this->userRepository->findByEmail($command->getEmail());

        if ($user === null) {
            throw new \RuntimeException('Invalid credentials.');
        }

        if (!$this->passwordHasher->verify($command->getPlainPassword(), $user->getPassword())) {
            throw new \RuntimeException('Invalid credentials.');
        }

        return $user;
    }
}
