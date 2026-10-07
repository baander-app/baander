<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ResendEmailVerificationCommand;
use App\Auth\Application\Port\EmailVerificationResendThrottleInterface;
use App\Auth\Application\Service\EmailVerificationIssuer;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends a signed-in user a new verification link, replacing the one sent earlier.
 *
 * The caller answers the same whatever happens here: nothing is sent for a verified address,
 * a disabled account, or a user over the per-user limit.
 */
final readonly class ResendEmailVerificationHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailVerificationResendThrottleInterface $throttle,
        private EmailVerificationIssuer $verification,
        private TransactionPortInterface $transaction,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ResendEmailVerificationCommand $command): void
    {
        $userId = Uuid::fromString($command->userId);
        if (!$this->throttle->tryAcquire($userId)) {
            return;
        }

        $user = $this->userRepository->findByUuid($userId);
        if ($user === null) {
            return;
        }

        $issued = $this->transaction->run(fn () => $this->verification->issue($user));
        $this->verification->deliver($user, $issued);
    }
}
