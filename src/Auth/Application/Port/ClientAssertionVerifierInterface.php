<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Domain\Model\OAuth\Client;

/**
 * Verifies a `private_key_jwt` client assertion (RFC 7523 as updated by RFC 10070)
 * against the client's registered keys.
 */
interface ClientAssertionVerifierInterface
{
    /**
     * Whether the assertion authenticates the client. An accepted assertion's `jti` is
     * recorded, so the same assertion is refused while it could still be accepted.
     *
     * @throws DpopStoreUnavailableException when the jti store cannot answer, or a client's
     *     published key set cannot be fetched now; a verdict is never guessed.
     */
    public function verify(string $assertion, Client $client): bool;
}
