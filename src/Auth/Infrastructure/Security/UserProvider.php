<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\User\PasswordHasher;
use App\Shared\Domain\Model\Email;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasher $passwordHasher,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $email = new Email($identifier);
        $user = $this->userRepository->findByEmail($email);

        if ($user === null) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return new SecurityUser(
            $user->getId()->toString(),
            $user->getEmail(),
            $user->getPassword(),
            $user->getRoles(),
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_debug_type($user)));
        }

        $userEntity = $this->userRepository->findByUuid(
            \App\Shared\Domain\Model\Uuid::fromString($user->getId()),
        );

        if ($userEntity === null) {
            throw new UserNotFoundException(sprintf('User with id "%s" not found.', $user->getId()));
        }

        return new SecurityUser(
            $userEntity->getId()->toString(),
            $userEntity->getEmail(),
            $userEntity->getPassword(),
            $userEntity->getRoles(),
        );
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface|PasswordUpgraderInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }

        $domainUser = $this->userRepository->findByUuid(
            \App\Shared\Domain\Model\Uuid::fromString($user->getId()),
        );

        if ($domainUser === null) {
            return;
        }

        $domainUser->changePassword($newHashedPassword);
        $this->userRepository->save($domainUser);
    }
}
