<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

/**
 * How a client authenticates at the token and pushed-request endpoints, named after
 * the RFC 7591 `token_endpoint_auth_method` values.
 *
 * A confidential client has exactly one method, fixed at registration; switching
 * between a secret and keys means registering a new client. Public clients use `none`.
 */
enum ClientAuthMethod: string
{
    /** A public client, identified by its client_id alone. */
    case None = 'none';

    /**
     * A confidential client that presents its secret, in the request body or in HTTP
     * Basic (`client_secret_basic`); never by both in one request.
     */
    case ClientSecretPost = 'client_secret_post';

    /** A confidential client that signs a JWT assertion with one of its registered keys (RFC 7523). */
    case PrivateKeyJwt = 'private_key_jwt';
}
