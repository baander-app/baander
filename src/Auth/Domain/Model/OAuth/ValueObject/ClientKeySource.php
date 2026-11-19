<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth\ValueObject;

use InvalidArgumentException;

/**
 * Where a `private_key_jwt` client's public signing keys come from: an inline JWK
 * Set or an https `jwks_uri`, never both (OpenID Connect Registration section 2).
 *
 * An inline set holds only public asymmetric keys, at most ten of them and at most
 * 64 KB encoded. When it holds more than one key, every key names a distinct `kid`,
 * so an assertion's `kid` header selects exactly one. A `jwks_uri` is https on the
 * default port and carries no credentials.
 */
final readonly class ClientKeySource
{
    public const int MAX_KEYS = 10;
    public const int MAX_JWKS_BYTES = 65536;

    /** JWK members that carry private or symmetric key material (RFC 7518 section 6). */
    private const array SECRET_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /** @param array{keys: list<array<string, mixed>>}|null $jwks */
    private function __construct(
        private ?array $jwks,
        private ?string $jwksUri,
    ) {
    }

    /** @param array<array-key, mixed> $jwks A JWK Set: an object with a `keys` list */
    public static function fromJwks(array $jwks): self
    {
        return new self(self::validKeySet($jwks), null);
    }

    public static function fromJwksUri(string $uri): self
    {
        self::assertValidUri($uri);

        return new self(null, $uri);
    }

    /**
     * The key source an administrator gave, which must be exactly one of the two.
     *
     * @param array<array-key, mixed>|null $jwks
     */
    public static function fromEither(?array $jwks, ?string $uri): self
    {
        return match (true) {
            $jwks !== null && $uri !== null => throw new InvalidArgumentException('Give either a JWK Set or a jwks_uri, not both.'),
            $jwks !== null => self::fromJwks($jwks),
            $uri !== null => self::fromJwksUri($uri),
            default => throw new InvalidArgumentException('A private_key_jwt client needs a JWK Set or a jwks_uri.'),
        };
    }

    /** @return array{keys: list<array<string, mixed>>}|null The inline JWK Set; null for a `jwks_uri` client */
    public function jwks(): ?array
    {
        return $this->jwks;
    }

    public function jwksUri(): ?string
    {
        return $this->jwksUri;
    }

    /**
     * @param array<array-key, mixed> $jwks
     * @return array{keys: list<array<string, mixed>>}
     */
    private static function validKeySet(array $jwks): array
    {
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys) || $keys === []) {
            throw new InvalidArgumentException('A JWK Set needs a non-empty "keys" list.');
        }
        if (count($keys) > self::MAX_KEYS) {
            throw new InvalidArgumentException(sprintf('A JWK Set may hold at most %d keys.', self::MAX_KEYS));
        }
        if (strlen(json_encode($jwks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) > self::MAX_JWKS_BYTES) {
            throw new InvalidArgumentException(sprintf('A JWK Set may be at most %d bytes.', self::MAX_JWKS_BYTES));
        }

        array_walk($keys, self::assertPublicAsymmetricKey(...));
        self::assertDistinctKids($keys);

        /** @var array{keys: list<array<string, mixed>>} $jwks */
        return $jwks;
    }

    private static function assertPublicAsymmetricKey(mixed $key): void
    {
        if (!is_array($key) || !is_string($key['kty'] ?? null) || $key['kty'] === '') {
            throw new InvalidArgumentException('Every key in a JWK Set needs a "kty" member.');
        }
        if ($key['kty'] === 'oct') {
            throw new InvalidArgumentException('A client key must be asymmetric; "oct" keys are not accepted.');
        }
        if (array_intersect(array_keys($key), self::SECRET_MEMBERS) !== []) {
            throw new InvalidArgumentException('A client key must be public; the JWK Set holds private key material.');
        }
    }

    /** @param list<array<string, mixed>> $keys */
    private static function assertDistinctKids(array $keys): void
    {
        if (count($keys) === 1) {
            return;
        }

        $kids = array_filter(
            array_map(static fn (array $key): mixed => $key['kid'] ?? null, $keys),
            static fn (mixed $kid): bool => is_string($kid) && $kid !== '',
        );
        if (count($kids) !== count($keys) || count(array_unique($kids)) !== count($keys)) {
            throw new InvalidArgumentException('Each key in a JWK Set of several keys needs its own "kid".');
        }
    }

    private static function assertValidUri(string $uri): void
    {
        $parts = parse_url($uri);
        $valid = is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && ($parts['port'] ?? 443) === 443
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && preg_match('/[\s\x00-\x1F\x7F]/', $uri) !== 1;
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('The jwks_uri "%s" must be an https URI on port 443 without credentials.', $uri));
        }
    }
}
