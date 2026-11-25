<?php

declare(strict_types=1);

namespace App\Auth\Domain\Model\OAuth\ValueObject;

use InvalidArgumentException;

/**
 * Where a `private_key_jwt` client's public signing keys come from: an inline JWK
 * Set or an https `jwks_uri`, never both (OpenID Connect Registration section 2).
 *
 * An inline set holds only public asymmetric keys, at most ten of them and at most
 * 64 KB encoded; an OKP key names the Ed25519 curve, the only one that signs here,
 * and an RSA key has a modulus of at most 4096 bits and the public exponent 65537.
 * When it holds more than one key, every key names a distinct `kid`, so an
 * assertion's `kid` header selects exactly one. A `jwks_uri` passes the syntactic
 * half of the outbound fetch policy: https on the default port, a dotted DNS name
 * rather than an IP literal, no credentials, fragment, whitespace or backslash.
 */
final readonly class ClientKeySource
{
    public const int MAX_KEYS = 10;
    public const int MAX_JWKS_BYTES = 65536;

    /** JWK members that carry private or symmetric key material (RFC 7518 section 6). */
    private const array SECRET_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    private const int MAX_RSA_MODULUS_BYTES = 512;

    /** 65537 as the bytes a JWK `e` member decodes to. */
    private const string RSA_PUBLIC_EXPONENT = "\x01\x00\x01";

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
        if (json_encode($jwks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) |> strlen(...) > self::MAX_JWKS_BYTES) {
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
        if ($key['kty'] === 'OKP' && ($key['crv'] ?? null) !== 'Ed25519') {
            throw new InvalidArgumentException('An OKP client key must name the Ed25519 curve.');
        }
        if ($key['kty'] === 'RSA') {
            self::assertRsaWithinBounds($key);
        }
    }

    /**
     * The assertion verifier's ceiling on an RSA modulus and its one public exponent, so a
     * key it refuses for either is refused when an administrator gives it or a fetch returns it.
     *
     * @param array<array-key, mixed> $key
     */
    private static function assertRsaWithinBounds(array $key): void
    {
        if (strlen(self::unsignedInteger($key['n'] ?? null)) > self::MAX_RSA_MODULUS_BYTES) {
            throw new InvalidArgumentException('An RSA client key may have a modulus of at most 4096 bits.');
        }
        if (self::unsignedInteger($key['e'] ?? null) !== self::RSA_PUBLIC_EXPONENT) {
            throw new InvalidArgumentException('An RSA client key must use the public exponent 65537.');
        }
    }

    /** The big-endian bytes of a base64url JWK integer, without leading zero bytes; none when the member is not a string. */
    private static function unsignedInteger(mixed $member): string
    {
        return is_string($member) ? ltrim(strtr($member, '-_', '+/') |> base64_decode(...), "\0") : '';
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
        if (count($kids) !== count($keys) || array_unique($kids) |> count(...) !== count($keys)) {
            throw new InvalidArgumentException('Each key in a JWK Set of several keys needs its own "kid".');
        }
    }

    /**
     * The checks the outbound fetch policy makes before resolving a URL, so a `jwks_uri`
     * it would refuse at every fetch is refused when an administrator gives it.
     */
    private static function assertValidUri(string $uri): void
    {
        $parts = parse_url($uri);
        $valid = is_array($parts)
            && filter_var($uri, FILTER_VALIDATE_URL) !== false
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['port'] ?? 443) === 443
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !str_contains($uri, '#')
            && preg_match('/[\s\x00-\x20\x7F\\\\]/', $uri) !== 1
            && self::isPublicDnsName(strtolower($parts['host'] ?? ''));
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('The jwks_uri "%s" must be an https URI on port 443 naming a dotted DNS host, without credentials or fragment.', $uri));
        }
    }

    /** A dotted hostname, never an IP literal, a single label such as `localhost`, or a name ending in a dot. */
    private static function isPublicDnsName(string $host): bool
    {
        return $host !== ''
            && str_contains($host, '.')
            && !str_ends_with($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
