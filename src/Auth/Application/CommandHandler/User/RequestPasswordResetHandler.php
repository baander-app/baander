<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\RequestPasswordResetCommand;
use App\Auth\Application\Port\PasswordResetDeliveryInterface;
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
        private readonly PasswordResetDeliveryInterface $delivery,
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
        // with any change of its email or password. Only its hash is stored, so the raw token
        // exists only here and in the delivery. It must never reach a domain event: events
        // are stored in the outbox.
        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(sprintf('+%d minutes', $this->tokenLifetimeMinutes));
        $this->passwordResetTokenRepository->issue($user->getId(), $token, $expiresAt);
        $this->delivery->deliver($user, $token, $expiresAt);
    }
}
