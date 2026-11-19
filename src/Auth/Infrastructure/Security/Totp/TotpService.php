<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\Totp;

use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

/** RFC 6238 codes with the authenticator-app defaults: SHA-1, six digits, 30-second steps. */
final readonly class TotpService
{
    /**
     * @param int $window Steps accepted on either side of the current one, for clock skew (0 = current step only)
     */
    public function __construct(
        private ClockInterface $clock,
        private string $issuer = 'Bånder',
        private int $window = 1,
    ) {
    }

    /**
     * Generate a new TOTP secret.
     *
     * @return string The base32-encoded secret
     */
    public function generateSecret(): string
    {
        return $this->totp()->getSecret();
    }

    /**
     * The time step whose code matches, searched from the current step minus the window to
     * the current step plus the window. When the codes of two steps collide, the later step
     * is returned, so spending it also retires the earlier one.
     *
     * @param string $secret The base32-encoded shared secret
     * @param string $code   The code the user entered
     */
    public function matchStep(string $secret, string $code): ?int
    {
        $totp = $this->totp($secret);
        $current = intdiv($this->clock->now()->getTimestamp() - $totp->getEpoch(), $totp->getPeriod());
        $matches = static fn (int $step): bool => hash_equals($totp->at($totp->getEpoch() + $step * $totp->getPeriod()), $code);

        $matched = array_filter(range(max(0, $current - $this->window), $current + $this->window), $matches);

        return $matched === [] ? null : max($matched);
    }

    /**
     * Get the otpauth:// URI for provisioning an authenticator app (QR code).
     *
     * @param string $secret The base32-encoded shared secret
     * @param string $email  The user's email address (used as the account label)
     */
    public function getProvisioningUri(string $secret, string $email): string
    {
        $totp = $this->totp($secret);
        $totp->setLabel($email);
        $totp->setIssuer($this->issuer);

        return $totp->getProvisioningUri();
    }

    private function totp(?string $secret = null): TOTP
    {
        return TOTP::create($secret, clock: $this->clock);
    }
}
