<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Domain\Model\User;

/**
 * Checks TOTP codes. A code matches a time step within the configured window
 * (`auth.totp.window` steps on either side of the current one); an enrolled user's
 * code counts once, because the matched step is spent.
 */
interface TotpVerifierInterface
{
    /**
     * Generate a new TOTP secret.
     *
     * @return string The base32-encoded secret
     */
    public function generateSecret(): string;

    /**
     * The time step a code matches for a plaintext secret, such as a pending setup's; nothing is spent.
     *
     * @param string $secret The base32-encoded shared secret
     */
    public function matchStep(string $secret, string $code): ?int;

    /**
     * The time step a code matches for the user's enrolled secret; nothing is spent.
     * Login spends the step later, inside the transaction that issues its tokens.
     *
     * @throws TotpSecretUnavailableException when the stored secret cannot be opened
     * @throws \LogicException when the user has no TOTP
     */
    public function matchEnrolledStep(User $user, string $code): ?int;

    /**
     * Whether the code matches the user's enrolled secret at a step not spent yet; the step
     * is spent at once, so the same code fails on any later or concurrent request.
     *
     * @throws TotpSecretUnavailableException when the stored secret cannot be opened
     * @throws \LogicException when the user has no TOTP
     */
    public function verifyAndSpend(User $user, string $code): bool;
}
