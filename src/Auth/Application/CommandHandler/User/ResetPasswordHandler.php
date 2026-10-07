<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ResetPasswordCommand;
use App\Auth\Application\Exception\PasswordResetException;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ResetPasswordHandler
{
    public function __construct(
        private PasswordResetTokenRepositoryInterface $passwordResetTokenRepository,
        private UserRepositoryInterface $userRepository,
        private PasswordChanger $passwordChanger,
    ) {
    }

    /**
     * @throws PasswordResetException for an unknown, expired or used token, or a disabled account
     */
    #[AsMessageHandler]
    public function __invoke(ResetPasswordCommand $command): void
    {
        // Redeem first, so an invalid token costs no password hashing. If the change fails
        // afterwards, the token is gone and the user requests a new one.
        $userId = $this->passwordResetTokenRepository->redeem($command->token, new \DateTimeImmutable());
        $user = $userId === null ? null : $this->userRepository->findByUuid($userId);

        if ($user === null || $user->isDisabled()) {
            throw PasswordResetException::invalidToken();
        }

        $this->passwordChanger->change($user, $command->password);
    }
}
