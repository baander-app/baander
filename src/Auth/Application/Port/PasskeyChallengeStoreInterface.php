<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\PasskeyChallengeUnavailableException;

/**
 * Single-use storage for WebAuthn ceremony options, shared by every worker.
 *
 * A record is bound to its ceremony, such as a registration for one user or an
 * anonymous login, and to the challenge key handed to the client. It can be taken
 * once, and only under the ceremony it was stored for.
 */
interface PasskeyChallengeStoreInterface
{
    /** @throws PasskeyChallengeUnavailableException */
    public function put(string $ceremony, string $challengeKey, string $options, int $ttlSeconds): void;

    /**
     * Returns the options and removes them in one step; null when they are absent,
     * expired, already taken or stored for another ceremony.
     *
     * @throws PasskeyChallengeUnavailableException
     */
    public function take(string $ceremony, string $challengeKey): ?string;
}
