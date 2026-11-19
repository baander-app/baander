<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\DpopStoreUnavailableException;

/**
 * Single use of client assertion identifiers (OpenID Connect Core section 9), per client
 * and apart from DPoP proof identifiers.
 */
interface ClientAssertionJtiStoreInterface
{
    /**
     * Records the client's jti on first sight and reports whether it was seen before.
     *
     * @param int $ttlSeconds How long the record lives: the assertion's remaining lifetime plus clock skew
     * @throws DpopStoreUnavailableException when the store cannot answer; a replay
     *     verdict is never guessed.
     */
    public function isReplay(string $clientId, string $jti, int $ttlSeconds): bool;
}
