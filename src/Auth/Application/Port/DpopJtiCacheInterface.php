<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\DpopStoreUnavailableException;

/**
 * Replay protection for DPoP proof identifiers (RFC 9449 §11.1).
 */
interface DpopJtiCacheInterface
{
    /**
     * Records the jti on first sight and reports whether it was seen before.
     *
     * The record expires after the proof acceptance window, so a jti is refused for
     * as long as a proof carrying it could still be accepted.
     *
     * @throws DpopStoreUnavailableException when the store cannot answer; a replay
     *     verdict is never guessed.
     */
    public function isReplay(string $jti, int $ttlSeconds): bool;
}
