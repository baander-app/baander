<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\EnableUserCommand;
use App\Auth\Application\Port\UserPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class EnableUserHandler
{
    public function __construct(
        private readonly UserPortInterface $userService,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(EnableUserCommand $command): void
    {
        $user = $this->resolveUser($command->getIdentifier());

        if (!$user->isDisabled()) {
            throw new \RuntimeException('User is not disabled.');
        }

        $user->enable();
        $this->userService->save($user);
    }

    private function resolveUser(string $identifier): \App\Auth\Domain\Model\User
    {
        if (str_contains($identifier, '@')) {
            $user = $this->userService->findByEmail(new Email($identifier));
        } else {
            $user = $this->userService->findByUuid(Uuid::fromString($identifier));
        }

        if ($user === null) {
            throw new \RuntimeException(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }
}
