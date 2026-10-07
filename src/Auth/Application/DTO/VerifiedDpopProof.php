<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * A token request's DPoP proof that passed the nonce and signature checks (RFC 9449).
 *
 * The token endpoint's proof listener stores it on the request under this class
 * name; the token endpoint issues tokens bound to the proof key and returns the
 * next nonce with every answer, since each nonce is accepted once.
 */
final readonly class VerifiedDpopProof
{
    /**
     * @param string $jkt Thumbprint of the proof key the tokens are bound to
     * @param string $nextNonce Server-issued nonce for the client's next DPoP proof
     */
    public function __construct(
        public string $jkt,
        public string $nextNonce,
    ) {
    }
}
