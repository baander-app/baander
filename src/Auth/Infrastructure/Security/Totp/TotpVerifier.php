<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Totp;

use App\Auth\Application\Port\TotpVerifierInterface;

final class TotpVerifier implements TotpVerifierInterface
{
    public function __construct(
        private readonly TotpService $totpService,
    ) {
    }

    public function generateSecret(): string
    {
        return $this->totpService->generateSecret();
    }

    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        return $this->totpService->verifyCode($secret, $code);
    }
}
