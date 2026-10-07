<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Auth\Application\Port\PasswordResetRequestThrottleInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RequestPasswordResetHandler
{
    /**
     * @param int $tokenLifetimeMinutes how long an issued token can be redeemed (PASSWORD_RESET_EXPIRE)
     */
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordResetTokenRepositoryInterface $passwordResetTokenRepository,
        private readonly PasswordResetRequestThrottleInterface $throttle,
        private readonly int $tokenLifetimeMinutes,
    ) {
        if ($tokenLifetimeMinutes < 1) {
            throw new \InvalidArgumentException('The password reset token lifetime must be at least one minute.');
        }
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

        // The token is tied to the account, not the address: it ends with the account and
        // with any change of its email or password. Only its hash is stored, so this is the
        // one place the raw token exists; nothing delivers it to the user yet.
        $token = bin2hex(random_bytes(32));
        $this->passwordResetTokenRepository->issue(
            $user->getId(),
            $token,
            new \DateTimeImmutable(sprintf('+%d minutes', $this->tokenLifetimeMinutes)),
        );
    }
}
