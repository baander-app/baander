<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS256;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use RuntimeException;

/**
 * A `private_key_jwt` client's disposable key and the client assertions it signs
 * (RFC 7523 as updated by RFC 10070): ES256 by default, or an RSA key signing RS256
 * or PS256.
 */
final readonly class SignedClientAssertion
{
    public const string TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private string $privateKey;
    /** @var array<string, string> */
    private array $jwk;

    public function __construct(private ?string $kid = 'client-key-1', private string $algorithm = 'ES256', int $rsaBits = 2048)
    {
        if ($algorithm !== 'ES256') {
            [$this->privateKey, $this->jwk] = self::rsaKey($kid, $rsaBits);

            return;
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $exportedPrivateKey)) {
            throw new RuntimeException('Cannot generate a disposable client key.');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Cannot read the disposable client public key.');
        }
        $this->privateKey = $exportedPrivateKey;
        $this->jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => SignedDpopProof::encode(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => SignedDpopProof::encode(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            ...($kid === null ? [] : ['kid' => $kid]),
        ];
    }

    /** @return array<string, string> The public key as the client registers it */
    public function publicJwk(): array
    {
        return $this->jwk;
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        return ['keys' => [$this->jwk]];
    }

    /**
     * An assertion valid for two minutes from `$now`. An override replaces a claim or
     * header; a null override removes it.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $headers
     */
    public function create(string $clientId, string $audience, array $claims = [], array $headers = [], ?int $now = null): string
    {
        $now ??= time();
        $claims = self::merge([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $audience,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + 120,
        ], $claims);
        $headers = self::merge(['typ' => 'client-authentication+jwt', 'alg' => $this->algorithm, 'kid' => $this->kid], $headers);

        $body = SignedDpopProof::encode(json_encode($headers, JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode($claims, JSON_THROW_ON_ERROR));

        return $body . '.' . SignedDpopProof::encode($this->sign($body));
    }

    private function sign(string $body): string
    {
        return match ($this->algorithm) {
            'ES256' => (new Sha256())->sign($body, InMemory::plainText($this->privateKey)),
            'RS256' => (new RsaSha256())->sign($body, InMemory::plainText($this->privateKey)),
            'PS256' => (new PS256())->sign(JWKFactory::createFromKey($this->privateKey), $body),
            default => throw new RuntimeException(sprintf('Unsupported fixture algorithm "%s".', $this->algorithm)),
        };
    }

    /** @return array{string, array<string, string>} The PEM private key and the public JWK */
    private static function rsaKey(?string $kid, int $bits): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        if ($key === false || !openssl_pkey_export($key, $exportedPrivateKey)) {
            throw new RuntimeException('Cannot generate a disposable client key.');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Cannot read the disposable client public key.');
        }

        return [$exportedPrivateKey, [
            'kty' => 'RSA',
            'n' => SignedDpopProof::encode($details['rsa']['n']),
            'e' => SignedDpopProof::encode($details['rsa']['e']),
            ...($kid === null ? [] : ['kid' => $kid]),
        ]];
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $defaults, array $overrides): array
    {
        return array_filter([...$defaults, ...$overrides], static fn (mixed $value): bool => $value !== null);
    }
}
