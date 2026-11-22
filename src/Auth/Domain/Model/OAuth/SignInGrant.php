<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

/**
 * The grant that started a refresh token chain. Each access token records it and every
 * rotation copies it, so a session keeps the origin of its first pair.
 *
 * Password and passkey sign-ins issue only to the first-party client, and the authorization
 * code and device code grants only to other clients. A token acts with first-party authority
 * only when its client is the first-party client and its chain began with a sign-in of the
 * account itself.
 */
enum SignInGrant: string
{
    case Password = 'password';
    case Passkey = 'passkey';
    case AuthorizationCode = 'authorization_code';
    case DeviceCode = 'device_code';

    /** Whether the account itself signed in, as only the first-party client's apps do. */
    public function isFirstPartySignIn(): bool
    {
        return match ($this) {
            self::Password, self::Passkey => true,
            self::AuthorizationCode, self::DeviceCode => false,
        };
    }
}
