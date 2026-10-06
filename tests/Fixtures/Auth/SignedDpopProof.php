<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Infrastructure\Security\OAuth\DpopJwkThumbprint;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use RuntimeException;

/** Ephemeral ES256 proofs using the same signature format as first-party clients. */
final readonly class SignedDpopProof
{
    private string $privateKey;
    /** @var array{kty: string, crv: string, x: string, y: string} */
    private array $jwk;

    public function __construct(?string $privateKey = null)
    {
        $key = $privateKey === null
            ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
            : openssl_pkey_get_private($privateKey);
        if ($key === false || !openssl_pkey_export($key, $exportedPrivateKey)) {
            throw new RuntimeException('Cannot generate disposable DPoP key.');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Cannot read disposable DPoP public key.');
        }
        $this->privateKey = $exportedPrivateKey;
        $this->jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::encode(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => self::encode(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    public function thumbprint(): string
    {
        return DpopJwkThumbprint::compute($this->jwk);
    }

    /** @param array<string, mixed>|null $jwk Override only for deliberately malformed proof fixtures. */
    public function create(string $method, string $uri, string $accessToken, ?array $jwk = null): string
    {
        return $this->sign([
            'jti' => bin2hex(random_bytes(16)),
            'htm' => $method,
            'htu' => $uri,
            'iat' => time(),
            'ath' => self::encode(hash('sha256', $accessToken, true)),
        ], $jwk);
    }

    /** Token-endpoint proof carrying the server nonce; `ath` is present only when a token is presented. */
    public function createWithNonce(string $method, string $uri, string $nonce, ?string $accessToken = null): string
    {
        $claims = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => $method,
            'htu' => $uri,
            'iat' => time(),
            'nonce' => $nonce,
        ];
        if ($accessToken !== null) {
            $claims['ath'] = self::encode(hash('sha256', $accessToken, true));
        }

        return $this->sign($claims);
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed>|null $jwk
     */
    private function sign(array $claims, ?array $jwk = null): string
    {
        $header = ['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $jwk ?? $this->jwk];
        $body = self::encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . self::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = (new Sha256())->sign($body, InMemory::plainText($this->privateKey));

        return $body . '.' . self::encode($signature);
    }
}
