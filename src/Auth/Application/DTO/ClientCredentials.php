<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

/**
 * What a back-channel request presents to authenticate its client: the `client_id`,
 * and a `client_secret` or a JWT client assertion (RFC 7521 section 4.2, RFC 7523).
 *
 * The request names its client in `client_id` every time, also when it carries an
 * assertion; the assertion's `iss` and `sub` must equal it. An empty value counts as
 * not sent.
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
    ) {
    }

    public function presentsSecret(): bool
    {
        return ($this->secret ?? '') !== '';
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
