<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;

/** Finds the user an operator or a command names by email address or UUID. */
final readonly class UserLookup
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @throws UserNotFoundException when no user has the email address or UUID
     */
    public function byIdentifier(string $identifier): User
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
