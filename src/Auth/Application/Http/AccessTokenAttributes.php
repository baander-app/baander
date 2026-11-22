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

    /**
     * The user's credential epoch the token was issued under, which the authenticator found
     * current. An approval made with the token carries it, so a password change voids it.
     */
    public const string CREDENTIAL_EPOCH = 'oauth_credential_epoch';

    /** True when the token's user has verified their current email address, absent or false otherwise. */
    public const string EMAIL_VERIFIED = 'oauth_email_verified';

    public static function isFirstParty(mixed $attribute): bool
    {
        return $attribute === true;
    }

    /** A request without the marker counts as unverified, so a missing attribute confines the account. */
    public static function isEmailVerified(mixed $attribute): bool
    {
        return $attribute === true;
    }

    /** Null when the request carries no access token id, so a credential change keeps no session. */
    public static function tokenId(mixed $attribute): ?string
    {
        return is_string($attribute) && $attribute !== '' ? $attribute : null;
    }

    /** Null when the request carries no epoch, which a caller must treat as unauthenticated. */
    public static function credentialEpoch(mixed $attribute): ?int
    {
        return is_int($attribute) ? $attribute : null;
    }

    /** @return list<string> */
    public static function scopes(mixed $attribute): array
    {
        return is_array($attribute)
            ? array_values(array_filter($attribute, is_string(...)))
            : [];
    }
}
