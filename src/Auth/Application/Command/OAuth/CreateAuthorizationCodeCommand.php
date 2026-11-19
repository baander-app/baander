<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\Uuid;

/**
 * The signed-in user approves or denies a client's pushed authorization request (RFC 9126 section 4).
 *
 * The handler answers with an AuthorizationResponseDTO, which carries a code
 * on approval and access_denied on denial, or throws an OAuthProtocolException.
 */
final readonly class CreateAuthorizationCodeCommand
{
    /**
     * @param string $clientId   The client_id the browser carried, which must be the client that pushed the request
     * @param string $requestUri The request URI the pushed authorization request endpoint returned
     * @param int    $credentialEpoch The credential epoch of the access token the decision was sent with;
     *                                an approval stores it on the code
     * @param bool   $approved   The user's decision
     */
    public function __construct(
        public Uuid $userId,
        public string $clientId,
        public string $requestUri,
        public int $credentialEpoch,
        public bool $approved = true,
    ) {
    }
}
