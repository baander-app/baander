<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth;

use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * OAuth 2.0 Client aggregate root.
 *
 * A client application that requests tokens on behalf of users. A confidential
 * client authenticates with exactly one method, fixed at registration: a secret,
 * stored only as its SHA-256 digest (see ClientSecret), or its own signing keys
 * (see ClientKeySource). Public clients have neither.
 */
final class Client
{
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_REDIRECT_URIS = 10;
    public const int MAX_REDIRECT_URI_LENGTH = 2000;

    private function __construct(
        private ClientState $state,
    ) {
    }

    /**
     * Create a new OAuth client from explicit flags.
     *
     * Administrators register clients through registerDevice(), registerPublic(),
     * registerConfidential() and registerConfidentialWithKeys(), which also validate
     * the redirect URIs. A confidential client holds either a secret or keys; its
     * authentication method follows from which one it holds.
     *
     * @param string[] $redirectUris
     * @param ?PublicId $publicId Pre-assigned public ID for seeding clients with a known
     *                            identity (e.g. the first-party client). Defaults to a new NanoID.
     */
    public static function create(
        string $name,
        array $redirectUris,
        ?ClientSecret $secret = null,
        bool $confidential = false,
        bool $firstParty = false,
        bool $personalAccessClient = false,
        bool $passwordClient = false,
        bool $deviceClient = false,
        ?Uuid $userId = null,
        ?PublicId $publicId = null,
        ?ClientKeySource $keySource = null,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Client name cannot be empty.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('Client name cannot be longer than %d characters.', self::MAX_NAME_LENGTH));
        }

        if ($confidential && ($secret === null) === ($keySource === null)) {
            throw new InvalidArgumentException('Confidential clients must have a secret or keys, not both.');
        }
        if (!$confidential && $secret !== null) {
            throw new InvalidArgumentException('Only confidential clients have a secret.');
        }
        if (!$confidential && $keySource !== null) {
            throw new InvalidArgumentException('Only confidential clients have keys.');
        }

        $now = new DateTimeImmutable();

        return new self(new ClientState(
            id: new Uuid(),
            publicId: $publicId ?? new PublicId(),
            name: $name,
            secretHash: $secret?->hash(),
            authMethod: match (true) {
                $secret !== null => ClientAuthMethod::ClientSecretPost,
                $keySource !== null => ClientAuthMethod::PrivateKeyJwt,
                default => ClientAuthMethod::None,
            },
            keySource: $keySource,
            redirectUris: array_values($redirectUris),
            personalAccessClient: $personalAccessClient,
            passwordClient: $passwordClient,
            deviceClient: $deviceClient,
            confidential: $confidential,
            firstParty: $firstParty,
            userId: $userId,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    /** A public client of the device authorization grant (RFC 8628), such as a TV app. */
    public static function registerDevice(string $name): self
    {
        return self::create(name: $name, redirectUris: [], deviceClient: true);
    }

    /**
     * A public client of the authorization code grant with PKCE, such as a native app.
     *
     * @param string[] $redirectUris
     */
    public static function registerPublic(string $name, array $redirectUris): self
    {
        return self::create(name: $name, redirectUris: self::validRedirectUris($redirectUris));
    }

    /**
     * A confidential client of the authorization code grant, authenticated with its secret.
     *
     * @param string[] $redirectUris
     */
    public static function registerConfidential(string $name, array $redirectUris, ClientSecret $secret): self
    {
        return self::create(
            name: $name,
            redirectUris: self::validRedirectUris($redirectUris),
            secret: $secret,
            confidential: true,
        );
    }

    /**
     * A confidential client of the authorization code grant, authenticated with a JWT
     * assertion signed by one of its own keys (`private_key_jwt`).
     *
     * @param string[] $redirectUris
     */
    public static function registerConfidentialWithKeys(string $name, array $redirectUris, ClientKeySource $keys): self
    {
        return self::create(
            name: $name,
            redirectUris: self::validRedirectUris($redirectUris),
            confidential: true,
            keySource: $keys,
        );
    }

    /**
     * Reconstitute a Client from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(ClientState $state): self
    {
        return new self($state);
    }

    /**
     * Create a personal access client (non-confidential, first-party).
     */
    public static function createPersonalAccess(string $name, ?Uuid $userId = null): self
    {
        return self::create(
            name: $name,
            redirectUris: ['http://localhost'],
            firstParty: true,
            personalAccessClient: true,
            userId: $userId,
        );
    }

    public function revoke(): void
    {
        if ($this->state->revoked) {
            return;
        }

        $this->state->revoked = true;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Client name cannot be empty.');
        }

        $this->state->name = trim($name);
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /** @param array<array-key, string> $redirectUris */
    public function updateRedirectUris(array $redirectUris): void
    {
        $this->state->redirectUris = array_values($redirectUris);
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Replaces a confidential client's secret; the previous one stops working at once.
     *
     * @throws DomainException The client does not authenticate with a secret, or is revoked
     */
    public function rotateSecret(ClientSecret $secret): void
    {
        if ($this->state->authMethod !== ClientAuthMethod::ClientSecretPost) {
            throw new DomainException('Only clients that authenticate with a secret have a secret.');
        }
        if ($this->state->revoked) {
            throw new DomainException('A revoked client cannot get a new secret.');
        }

        $this->state->secretHash = $secret->hash();
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Replaces a key client's inline key set or `jwks_uri`; the previous keys stop working at once.
     *
     * @throws DomainException The client does not authenticate with keys, or is revoked
     */
    public function replaceKeys(ClientKeySource $keys): void
    {
        if ($this->state->authMethod !== ClientAuthMethod::PrivateKeyJwt) {
            throw new DomainException('Only clients that authenticate with keys have keys.');
        }
        if ($this->state->revoked) {
            throw new DomainException('A revoked client cannot get new keys.');
        }

        $this->state->keySource = $keys;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /** Whether the presented secret is this confidential client's secret, compared in constant time. */
    public function authenticatesWith(?string $presentedSecret): bool
    {
        $hash = $this->state->secretHash;

        return $this->state->confidential && $hash !== null && $presentedSecret !== null
            && ClientSecret::matches($hash, $presentedSecret);
    }

    public function getType(): ClientType
    {
        return match (true) {
            $this->state->personalAccessClient => ClientType::PersonalAccess,
            $this->state->firstParty => ClientType::FirstParty,
            $this->state->deviceClient => ClientType::Device,
            $this->state->confidential => ClientType::Confidential,
            default => ClientType::Public,
        };
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getName(): string
    {
        return $this->state->name;
    }

    /** The SHA-256 digest of a confidential client's secret; null for public clients. */
    public function getSecretHash(): ?string
    {
        return $this->state->secretHash;
    }

    public function getAuthMethod(): ClientAuthMethod
    {
        return $this->state->authMethod;
    }

    /** The public signing keys of a `private_key_jwt` client; null for every other method. */
    public function getKeySource(): ?ClientKeySource
    {
        return $this->state->keySource;
    }

    /**
     * @return string[]
     */
    public function getRedirectUris(): array
    {
        return $this->state->redirectUris;
    }

    public function isPersonalAccessClient(): bool
    {
        return $this->state->personalAccessClient;
    }

    public function isPasswordClient(): bool
    {
        return $this->state->passwordClient;
    }

    public function isDeviceClient(): bool
    {
        return $this->state->deviceClient;
    }

    public function isConfidential(): bool
    {
        return $this->state->confidential;
    }

    public function isFirstParty(): bool
    {
        return $this->state->firstParty;
    }

    public function isRevoked(): bool
    {
        return $this->state->revoked;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getUserId(): ?Uuid
    {
        return $this->state->userId;
    }

    public function isOwnedBy(Uuid $userId): bool
    {
        if ($this->state->userId === null) {
            return false;
        }

        return $this->state->userId->equals($userId);
    }

    public function getState(): ClientState
    {
        return $this->state;
    }

    /**
     * Redirect URIs of an authorization code client (RFC 6749 section 3.1.2, RFC 8252, RFC 9700 section 2.1).
     *
     * Each URI is absolute, without a fragment, and compared exactly at the
     * authorization endpoint. Plain http is allowed only for a loopback host
     * (RFC 8252 section 7.3); a native app's private-use scheme must be in
     * reverse domain form, such as app.baander.tv:/callback (RFC 8252 section 7.1).
     *
     * @param string[] $redirectUris
     * @return list<string>
     */
    private static function validRedirectUris(array $redirectUris): array
    {
        $uris = array_values(array_unique(array_map('trim', $redirectUris)));
        if ($uris === [] || $uris === ['']) {
            throw new InvalidArgumentException('At least one redirect URI is required.');
        }
        if (count($uris) > self::MAX_REDIRECT_URIS) {
            throw new InvalidArgumentException(sprintf('At most %d redirect URIs are allowed.', self::MAX_REDIRECT_URIS));
        }

        foreach ($uris as $uri) {
            self::assertValidRedirectUri($uri);
        }

        return $uris;
    }

    private static function assertValidRedirectUri(string $uri): void
    {
        if ($uri === '' || strlen($uri) > self::MAX_REDIRECT_URI_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $uri) === 1) {
            throw new InvalidArgumentException(sprintf('The redirect URI "%s" is not valid.', $uri));
        }

        $parts = parse_url($uri);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if ($scheme === '' || str_contains($uri, '#')) {
            throw new InvalidArgumentException(sprintf('The redirect URI "%s" must be absolute and have no fragment.', $uri));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(sprintf('The redirect URI "%s" cannot carry credentials.', $uri));
        }

        $host = strtolower($parts['host'] ?? '');
        $valid = match ($scheme) {
            'https' => $host !== '',
            'http' => in_array($host, ['localhost', '127.0.0.1', '[::1]'], true),
            'javascript', 'data', 'file', 'vbscript' => false,
            default => str_contains($scheme, '.'),
        };
        if (!$valid) {
            throw new InvalidArgumentException(sprintf(
                'The redirect URI "%s" must use https, http on a loopback host, or a private-use scheme in reverse domain form.',
                $uri,
            ));
        }
    }
}
