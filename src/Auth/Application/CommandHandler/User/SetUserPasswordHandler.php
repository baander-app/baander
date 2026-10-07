<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class SetUserPasswordHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PasswordChanger $passwordChanger,
    ) {
    }

    /**
     * @throws UserNotFoundException when no user has the email address or UUID
     */
    #[AsMessageHandler]
    public function __invoke(SetUserPasswordCommand $command): void
    {
        $this->passwordChanger->change($this->resolveUser($command->identifier), $command->password);
    }

    private function resolveUser(string $identifier): User
    {
        try {
            $user = str_contains($identifier, '@')
                ? $this->userRepository->findByEmail(new Email($identifier))
                : $this->userRepository->findByUuid(Uuid::fromString($identifier));
        } catch (\InvalidArgumentException) {
            $user = null;
        }

        return $user ?? throw UserNotFoundException::forIdentifier($identifier);
    }
}
