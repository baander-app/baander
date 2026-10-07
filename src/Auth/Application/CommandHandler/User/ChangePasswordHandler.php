<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangePasswordCommand;
use App\Auth\Application\Exception\CurrentPasswordMismatchException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ChangePasswordHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private AccessTokenRepositoryInterface $accessTokenRepository,
        private PasswordHasherInterface $passwordHasher,
        private PasswordChanger $passwordChanger,
    ) {
    }

    /**
     * @throws CurrentPasswordMismatchException when the current password is wrong
     */
    #[AsMessageHandler]
    public function __invoke(ChangePasswordCommand $command): void
    {
        $user = $this->userRepository->findByUuid(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forIdentifier($command->userId);

        if (!$this->passwordHasher->verify($command->currentPassword, $user->getPassword())) {
            throw CurrentPasswordMismatchException::create();
        }

        // Keep the requesting session only when its token belongs to this user.
        $current = $command->currentAccessTokenId === null
            ? null
            : $this->accessTokenRepository->findByTokenId(TokenId::fromString($command->currentAccessTokenId));
        if ($current !== null && $current->getUser()?->getId()->equals($user->getId()) !== true) {
            $current = null;
        }

        $this->passwordChanger->change($user, $command->newPassword, $current);
    }
}
