<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Auth\Application\Port\PasswordResetRequestThrottleInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Ulid;

final class RequestPasswordResetHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordResetTokenRepositoryInterface $passwordResetTokenRepository,
        private readonly PasswordResetRequestThrottleInterface $throttle,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RequestPasswordResetCommand $command): void
    {
        // Charge the address before looking it up, so known and unknown addresses
        // consume the same allowance. The caller answers both outcomes identically.
        if (!$this->throttle->tryAcquire($command->getEmail())) {
            return;
        }

        $user = $this->userRepository->findByEmail($command->getEmail());

        if ($user === null) {
            // Do not reveal whether the email exists (security best practice).
            return;
        }

        $email = $command->getEmail()->toString();
        $token = Ulid::generate();

        $this->passwordResetTokenRepository->save($email, $token);
    }
}
