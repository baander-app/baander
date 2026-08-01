<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Domain\Model\OAuth\AccessToken;

interface JwtGeneratorInterface
{
    /**
     * Generate a JWT access token string from a domain AccessToken.
     *
     * The JWT is signed with RS256 and contains claims compatible with
     * league/oauth2-server's BearerTokenValidator.
     */
    public function generate(AccessToken $accessToken, ?string $dpopJkt = null): string;
}
