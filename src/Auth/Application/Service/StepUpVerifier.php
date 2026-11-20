<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\StepUpFailedException;
use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\StepUpAttemptThrottleInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Model\User;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Confirms that the signed-in user is present before a sensitive change of their own: their
 * current password, plus a current TOTP code when TOTP is on. The code's time step is spent,
 * so the same code confirms one change only. Every attempt counts against the account's
 * step-up limit, checked before anything else.
 */
final readonly class StepUpVerifier
{
    public function __construct(
        private StepUpAttemptThrottleInterface $throttle,
        private PasswordHasherInterface $passwordHasher,
        private TotpVerifierInterface $totpVerifier,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws StepUpRateLimitedException      when the account has used up its attempts
     * @throws RateLimiterUnavailableException when the limit cannot be checked
     * @throws StepUpFailedException           when the password or the code does not match
     */
    public function verify(User $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $totpCode): void
    {
        $this->throttle->acquire($user->getId());

        // A wrong password never reaches the code check, so it cannot spend a step.
        if (!$this->passwordHasher->verify($password, $user->getPassword()) || !$this->totpConfirms($user, $totpCode)) {
            throw StepUpFailedException::create();
        }
    }

    private function totpConfirms(User $user, ?string $code): bool
    {
        if (!$user->hasTotp()) {
            return true;
        }
        if ($code === null || $code === '') {
            return false;
        }

        try {
            return $this->totpVerifier->verifyAndSpend($user, $code);
        } catch (TotpSecretUnavailableException) {
            $this->logger->warning('Step-up refused: the stored TOTP secret cannot be decrypted with AUTH_TOTP_ENCRYPTION_KEY. Reset the account\'s TOTP to let it confirm changes.', [
                'user_id' => $user->getPublicId()->toString(),
            ]);

            return false;
        }
    }
}
