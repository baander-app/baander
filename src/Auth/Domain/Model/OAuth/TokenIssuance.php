<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

/** How a stored access token was issued: the sign-in grant of its chain and its credential epoch. */
final readonly class TokenIssuance
{
    public function __construct(
        public SignInGrant $grant,
        public int $credentialEpoch,
    ) {
    }
}
