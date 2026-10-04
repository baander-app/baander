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

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Cannot generate disposable DPoP key.');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Cannot read disposable DPoP public key.');
        }
        $this->privateKey = $privateKey;
        $this->jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::encode($details['ec']['x']),
            'y' => self::encode($details['ec']['y']),
        ];
    }

    public function thumbprint(): string
    {
        return DpopJwkThumbprint::compute($this->jwk);
    }

    public function create(string $method, string $uri, string $accessToken): string
    {
        $header = ['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $this->jwk];
        $claims = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => $method,
            'htu' => $uri,
            'iat' => time(),
            'ath' => self::encode(hash('sha256', $accessToken, true)),
        ];
        $body = self::encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . self::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = (new Sha256())->sign($body, InMemory::plainText($this->privateKey));

        return $body . '.' . self::encode($signature);
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
