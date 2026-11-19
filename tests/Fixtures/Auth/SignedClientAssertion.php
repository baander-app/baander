<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use RuntimeException;

/**
 * A `private_key_jwt` client's disposable ES256 key and the client assertions it signs
 * (RFC 7523 as updated by RFC 10070).
 */
final readonly class SignedClientAssertion
{
    public const string TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private string $privateKey;
    /** @var array<string, string> */
    private array $jwk;

    public function __construct(private ?string $kid = 'client-key-1')
    {
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
        $headers = self::merge(['typ' => 'client-authentication+jwt', 'alg' => 'ES256', 'kid' => $this->kid], $headers);

        $body = SignedDpopProof::encode(json_encode($headers, JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = (new Sha256())->sign($body, InMemory::plainText($this->privateKey));

        return $body . '.' . SignedDpopProof::encode($signature);
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
