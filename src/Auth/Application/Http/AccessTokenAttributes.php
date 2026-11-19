<?php

declare(strict_types=1);

namespace App\Auth\Application\Http;

/**
 * The request attributes the API authenticator sets from the access token it accepted.
 *
 * A token is first-party only when it was issued to Baander's own client; every other
 * token is delegated and reaches only what its scopes cover. A request that carries no
 * first-party marker counts as delegated with no scopes, so a missing attribute denies.
 */
final class AccessTokenAttributes
{
    /** The access token's `jti`. */
    public const string TOKEN_ID = 'oauth_access_token_id';

    /** The public ID of the client the token was issued to. */
    public const string CLIENT_ID = 'oauth_client_id';

    /** True for a token of Baander's own client, absent or false otherwise. */
    public const string FIRST_PARTY = 'oauth_first_party';

    /** The scopes a delegated token was granted; empty for a first-party token. */
    public const string SCOPES = 'oauth_scopes';

    public static function isFirstParty(mixed $attribute): bool
    {
        return $attribute === true;
    }

    /** @return list<string> */
    public static function scopes(mixed $attribute): array
    {
        return is_array($attribute)
            ? array_values(array_filter($attribute, is_string(...)))
            : [];
    }
}
