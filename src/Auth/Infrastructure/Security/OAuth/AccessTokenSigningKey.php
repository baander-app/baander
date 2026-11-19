<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\AccessTokenSigningKeyInterface;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Throwable;

/**
 * The RS256 key pair's public half, read from the configured PEM file once per worker.
 */
final class AccessTokenSigningKey implements AccessTokenSigningKeyInterface
{
    /** @var array{kty: string, use: string, alg: string, kid: string, n: string, e: string}|null */
    private ?array $jwk = null;
    private ?string $pem = null;

    public function __construct(private readonly string $publicKeyPath)
    {
    }

    public function publicJwk(): array
    {
        return $this->jwk ??= self::jwkOf(self::openPublicKey($this->pem()));
    }

    public function tokenIdOf(string $jwt): ?string
    {
        try {
            $token = (new Parser(new JoseEncoder()))->parse($jwt);
            $signedByThisKey = (new Validator())->validate($token, new SignedWith(new Sha256(), InMemory::plainText($this->pem())));
        } catch (Throwable) {
            // Whatever does not parse as a JWT is not one of ours.
            return null;
        }
        if (!$signedByThisKey || !$token instanceof UnencryptedToken) {
            return null;
        }

        $tokenId = $token->claims()->get('jti');

        return is_string($tokenId) && $tokenId !== '' ? $tokenId : null;
    }

    /**
     * The public JWK of an RSA key, private or public, with its RFC 7638 thumbprint as `kid`.
     *
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    public static function jwkOf(OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('The access token signing key is not an RSA key.');
        }

        $members = [
            'kty' => 'RSA',
            'n' => self::base64UrlEncode($details['rsa']['n']),
            'e' => self::base64UrlEncode($details['rsa']['e']),
        ];

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => DpopJwkThumbprint::compute($members),
            'n' => $members['n'],
            'e' => $members['e'],
        ];
    }

    private function pem(): string
    {
        if ($this->pem === null) {
            $pem = file_get_contents($this->publicKeyPath);
            if ($pem === false || $pem === '') {
                throw new RuntimeException(sprintf('Unable to read public key from "%s".', $this->publicKeyPath));
            }
            $this->pem = $pem;
        }

        return $this->pem;
    }

    private static function openPublicKey(string $pem): OpenSSLAsymmetricKey
    {
        return openssl_pkey_get_public($pem) ?: throw new RuntimeException('Failed to parse the access token public key.');
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
