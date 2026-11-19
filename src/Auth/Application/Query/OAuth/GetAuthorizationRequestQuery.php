<?php

declare(strict_types=1);

namespace App\Auth\Application\Query\OAuth;

use App\Shared\Domain\Model\Uuid;

/**
 * Looks up a pushed authorization request (RFC 9126 section 4) for the signed-in user before they decide on it.
 *
 * Looking the request up does not use it up. The handler answers with an
 * AuthorizationRequestDTO or an OAuthProtocolException.
 */
final readonly class GetAuthorizationRequestQuery
{
    /**
     * @param string $clientId   The client_id the browser carried, which must be the client that pushed the request
     * @param string $requestUri The request URI the pushed authorization request endpoint returned
     */
    public function __construct(
        public Uuid $userId,
        public string $clientId,
        public string $requestUri,
    ) {
    }
}
