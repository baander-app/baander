<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * What a back-channel request presents to authenticate its client: the `client_id`,
 * and a `client_secret` in the body or in HTTP Basic (RFC 6749 section 2.3.1), or a
 * JWT client assertion (RFC 7521 section 4.2, RFC 7523).
 *
 * The request names its client every time, also when it carries an assertion; the
 * assertion's `iss` and `sub` must equal it. An empty body value counts as not sent;
 * an HTTP Basic header counts as sent even with an empty password.
 */
final readonly class ClientCredentials
{
    /** The only `client_assertion_type` accepted: a JWT bearer assertion (RFC 7523 section 2.2). */
    public const string JWT_BEARER_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    public function __construct(
        public string $clientId,
        public ?string $secret = null,
        public ?string $assertion = null,
        public ?string $assertionType = null,
        /** The password of an HTTP Basic `Authorization` header; null when the request sent none. */
        public ?string $basicSecret = null,
    ) {
    }

    public function presentsSecret(): bool
    {
        return ($this->secret ?? '') !== '' || $this->basicSecret !== null;
    }

    /** The secret presented by exactly one means, or null when none was sent or both were. */
    public function presentedSecret(): ?string
    {
        $inBody = ($this->secret ?? '') !== '';

        return match (true) {
            $inBody && $this->basicSecret !== null => null,
            $inBody => $this->secret,
            default => $this->basicSecret,
        };
    }

    public function presentsAssertion(): bool
    {
        return ($this->assertion ?? '') !== '' || ($this->assertionType ?? '') !== '';
    }

    /** A JWT bearer assertion, or null when the request presents none or another assertion type. */
    public function jwtBearerAssertion(): ?string
    {
        return $this->assertionType === self::JWT_BEARER_ASSERTION_TYPE && ($this->assertion ?? '') !== ''
            ? $this->assertion
            : null;
    }
}
