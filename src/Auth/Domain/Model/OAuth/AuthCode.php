<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\User;

use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateInterval;
use InvalidArgumentException;

/**
 * OAuth 2.0 Authorization Code aggregate root.
 *
 * A short-lived, single-use code issued at the authorization endpoint. Every code
 * carries an S256 PKCE challenge (RFC 7636) and the redirect URI it was issued
 * for; redeeming it requires the matching code verifier and the same redirect URI
 * (RFC 6749 section 4.1.3, RFC 9700 section 2.1.1).
 */
final class AuthCode
{
    public const string CODE_CHALLENGE_METHOD = 'S256';

    private function __construct(
        private AuthCodeState $state,
    ) {
    }

    /**
     * Create a new authorization code.
     *
     * @param Scope[] $scopes
     */
    public static function create(
        User $user,
        Client $client,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
        array $scopes = [],
        ?DateInterval $ttl = null,
    ): self {
        if ($codeChallengeMethod !== self::CODE_CHALLENGE_METHOD) {
            throw new InvalidArgumentException('Authorization codes require the S256 code challenge method.');
        }
        // An S256 challenge is the unpadded base64url SHA-256 digest: 43 characters.
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $codeChallenge) !== 1) {
            throw new InvalidArgumentException('The code challenge is not an S256 challenge.');
        }
        if (trim($redirectUri) === '') {
            throw new InvalidArgumentException('Authorization codes require a redirect URI.');
        }

        $expiresAt = null;
        if ($ttl !== null) {
            $expiresAt = (new DateTimeImmutable())->add($ttl);
        }

        return new self(new AuthCodeState(
            id: new Uuid(),
            codeId: TokenId::generate(),
            user: $user,
            client: $client,
            scopes: $scopes,
            expiresAt: $expiresAt,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            redirectUri: $redirectUri,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
        ));
    }

    /**
     * Reconstitute an AuthCode from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(AuthCodeState $state): self
    {
        return new self($state);
    }

    public function revoke(): void
    {
        if ($this->state->revoked) {
            return;
        }

        $this->state->revoked = true;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        if ($this->state->expiresAt === null) {
            return false;
        }

        return $this->state->expiresAt < new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getCodeId(): TokenId
    {
        return $this->state->codeId;
    }

    public function getUser(): User
    {
        return $this->state->user;
    }

    public function getClient(): Client
    {
        return $this->state->client;
    }

    /**
     * @return Scope[]
     */
    public function getScopes(): array
    {
        return $this->state->scopes;
    }

    /**
     * @return string[]
     */
    public function getScopeIdentifiers(): array
    {
        return array_map(fn (Scope $scope) => $scope->toString(), $this->state->scopes);
    }

    public function isRevoked(): bool
    {
        return $this->state->revoked;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->state->expiresAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): AuthCodeState
    {
        return $this->state;
    }

    public function getRedirectUri(): string
    {
        return $this->state->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->state->codeChallenge;
    }

    public function getCodeChallengeMethod(): string
    {
        return $this->state->codeChallengeMethod;
    }

    /** RFC 7636 section 4.6: BASE64URL(SHA256(code_verifier)) must equal the stored challenge. */
    public function matchesCodeVerifier(?string $codeVerifier): bool
    {
        // Section 4.1: 43 to 128 unreserved characters.
        if ($codeVerifier === null || preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeVerifier) !== 1) {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($this->state->codeChallenge, $computed);
    }

    public function isIssuedFor(string $redirectUri): bool
    {
        return hash_equals($this->state->redirectUri, $redirectUri);
    }
}
