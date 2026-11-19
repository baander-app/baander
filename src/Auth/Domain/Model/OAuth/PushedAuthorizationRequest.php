<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An authorization request a client pushed on the back channel (RFC 9126).
 *
 * The client receives a request URI for it and sends the user's browser to the
 * authorization endpoint with only that URI. The request holds the parameters the
 * client pushed, among them its S256 PKCE challenge and the SHA-256 thumbprint of
 * the DPoP key that the resulting authorization code is bound to. It expires after
 * a short lifetime, and the user's decision uses it up.
 */
final class PushedAuthorizationRequest
{
    /** RFC 9126 section 2.2: the URN namespace registered for request URIs. */
    public const string REQUEST_URI_PREFIX = 'urn:ietf:params:oauth:request_uri:';

    /** The longest state a client may push, in bytes; chk_oauth_pushed_authorization_requests_state_length holds the same bound. */
    public const int MAX_STATE_BYTES = 1024;

    private function __construct(
        private PushedAuthorizationRequestState $state,
    ) {
    }

    /** @param list<Scope> $scopes */
    public static function create(
        Uuid $clientId,
        string $redirectUri,
        array $scopes,
        ?string $state,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $dpopJkt,
        DateInterval $lifetime,
        DateTimeImmutable $now,
    ): self {
        if ($codeChallengeMethod !== AuthCode::CODE_CHALLENGE_METHOD) {
            throw new InvalidArgumentException('Pushed authorization requests require the S256 code challenge method.');
        }
        if (!self::isSha256Digest($codeChallenge)) {
            throw new InvalidArgumentException('The code challenge is not an S256 challenge.');
        }
        if (!self::isSha256Digest($dpopJkt)) {
            throw new InvalidArgumentException('The DPoP key thumbprint is not a SHA-256 thumbprint.');
        }
        if (trim($redirectUri) === '') {
            throw new InvalidArgumentException('Pushed authorization requests require a redirect URI.');
        }

        return new self(new PushedAuthorizationRequestState(
            id: new Uuid(),
            requestId: TokenId::generate(),
            clientId: $clientId,
            redirectUri: $redirectUri,
            scopes: $scopes,
            state: $state,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
            dpopJkt: $dpopJkt,
            expiresAt: $now->add($lifetime),
            createdAt: $now,
        ));
    }

    /** For the repository layer only. */
    public static function reconstitute(PushedAuthorizationRequestState $state): self
    {
        return new self($state);
    }

    /** The request is usable while its expiry lies ahead, as the consuming statement checks it. */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->state->expiresAt <= $now;
    }

    public function isConsumed(): bool
    {
        return $this->state->consumedAt !== null;
    }

    public function requestUri(): string
    {
        return self::REQUEST_URI_PREFIX . $this->state->requestId->toString();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getRequestId(): TokenId
    {
        return $this->state->requestId;
    }

    public function getClientId(): Uuid
    {
        return $this->state->clientId;
    }

    public function getRedirectUri(): string
    {
        return $this->state->redirectUri;
    }

    /** @return list<Scope> */
    public function getScopes(): array
    {
        return $this->state->scopes;
    }

    /** @return list<string> */
    public function getScopeIdentifiers(): array
    {
        return array_map(static fn (Scope $scope): string => $scope->toString(), $this->state->scopes);
    }

    public function getState(): ?string
    {
        return $this->state->state;
    }

    public function getCodeChallenge(): string
    {
        return $this->state->codeChallenge;
    }

    public function getCodeChallengeMethod(): string
    {
        return $this->state->codeChallengeMethod;
    }

    public function getDpopJkt(): string
    {
        return $this->state->dpopJkt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->state->expiresAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->state->consumedAt;
    }

    /** An S256 challenge and a JWK SHA-256 thumbprint are both unpadded base64url digests: 43 characters. */
    private static function isSha256Digest(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $value) === 1;
    }
}
