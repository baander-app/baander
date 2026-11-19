<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Totp;

use App\Auth\Application\Port\TotpSecretCipherInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use LogicException;

final readonly class TotpVerifier implements TotpVerifierInterface
{
    public function __construct(
        private TotpService $totpService,
        private TotpSecretCipherInterface $cipher,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function generateSecret(): string
    {
        return $this->totpService->generateSecret();
    }

    public function matchStep(string $secret, string $code): ?int
    {
        return $this->totpService->matchStep($secret, $code);
    }

    public function matchEnrolledStep(User $user, string $code): ?int
    {
        $ciphertext = $user->getTotpSecretCiphertext() ?? throw new LogicException('The user has no TOTP secret.');

        return $this->totpService->matchStep($this->cipher->open($ciphertext), $code);
    }

    public function verifyAndSpend(User $user, string $code): bool
    {
        $step = $this->matchEnrolledStep($user, $code);

        return $step !== null && $this->userRepository->spendTotpStep($user->getId(), $step);
    }
}
