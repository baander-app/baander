<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

/**
 * What an OAuth client is registered for.
 *
 * Administrators register and manage device, public and confidential clients.
 * The first-party client that password and passkey login issue to is seeded by
 * app:auth:setup-clients; users create their own personal access clients.
 */
enum ClientType: string
{
    /** First-party app login (the SPA client). */
    case FirstParty = 'first_party';

    /** A user's personal access client. */
    case PersonalAccess = 'personal_access';

    /** Public client of the device authorization grant (RFC 8628), such as a TV app. */
    case Device = 'device';

    /** Public client of the authorization code grant with PKCE, such as a native or browser app. */
    case Public = 'public';

    /** Confidential client of the authorization code grant, authenticated with a client secret. */
    case Confidential = 'confidential';

    /** Administrators may register, rotate and revoke clients of this type. */
    public function isAdministered(): bool
    {
        return match ($this) {
            self::Device, self::Public, self::Confidential => true,
            self::FirstParty, self::PersonalAccess => false,
        };
    }

    /** @return list<self> */
    public static function administered(): array
    {
        return [self::Device, self::Public, self::Confidential];
    }
}
