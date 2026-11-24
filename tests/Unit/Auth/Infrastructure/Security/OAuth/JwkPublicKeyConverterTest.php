<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Infrastructure\Security\OAuth\JwkPublicKeyConverter;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The RSA keys the converter verifies with: a modulus of at most 4096 bits and the public
 * exponent 65537, so the sender's choice of key does not set the cost of a verification.
 */
final class JwkPublicKeyConverterTest extends TestCase
{
    private const string PAYLOAD = 'header.claims';

    /** @return iterable<string, array{string, int}> */
    public static function rsaKeysWithinTheBounds(): iterable
    {
        yield 'PS256 from a 2048-bit key' => ['PS256', 2048];
        yield 'PS256 from a 4096-bit key' => ['PS256', 4096];
        yield 'RS256 from a 2048-bit key' => ['RS256', 2048];
    }

    #[DataProvider('rsaKeysWithinTheBounds')]
    public function testASignatureFromAnRsaKeyWithinTheBoundsVerifies(string $alg, int $bits): void
    {
        [$jwk, $signature] = self::signedByAFreshRsaKey($alg, $bits);

        self::assertTrue(JwkPublicKeyConverter::verify($jwk, $alg, self::PAYLOAD, $signature));
        self::assertFalse(JwkPublicKeyConverter::verify($jwk, $alg, self::PAYLOAD . '.', $signature));
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function rsaKeysOutsideTheBounds(): iterable
    {
        $keys = [
            'an 8192-bit modulus' => [self::rsaJwk(modulusBytes: 1024), 'RSA keys must be at most 4096 bits.'],
            'a 4104-bit modulus' => [self::rsaJwk(modulusBytes: 513), 'RSA keys must be at most 4096 bits.'],
            'exponent 3' => [self::rsaJwk(exponent: "\x03"), 'RSA keys must use the public exponent 65537.'],
        ];
        foreach (['PS256', 'RS256'] as $alg) {
            foreach ($keys as $name => [$jwk, $message]) {
                yield $alg . ' with ' . $name => [$alg, $jwk, $message];
            }
        }
    }

    /**
     * The key is refused for its shape, whatever the signature: the one sent here is as long
     * as the modulus and numerically below it, so nothing else about it stops a verification.
     *
     * @param array<string, string> $jwk
     */
    #[DataProvider('rsaKeysOutsideTheBounds')]
    public function testAnRsaKeyOutsideTheBoundsIsRefused(string $alg, array $jwk, string $message): void
    {
        $modulusBytes = strlen(JwkPublicKeyConverter::base64urlDecode($jwk['n']));
        $signature = SignedDpopProof::encode("\x00" . str_repeat("\x01", $modulusBytes - 1));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        JwkPublicKeyConverter::verify($jwk, $alg, self::PAYLOAD, $signature);
    }

    /** @return array<string, string> A public key of the given shape; the modulus is odd with its top bit set */
    private static function rsaJwk(int $modulusBytes = 256, string $exponent = "\x01\x00\x01"): array
    {
        return [
            'kty' => 'RSA',
            'n' => SignedDpopProof::encode(str_repeat("\xff", $modulusBytes)),
            'e' => SignedDpopProof::encode($exponent),
        ];
    }

    /** @return array{array<string, string>, string} The public JWK and its base64url signature over the payload */
    private static function signedByAFreshRsaKey(string $alg, int $bits): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        $padding = $alg === 'PS256' ? OPENSSL_PKCS1_PSS_PADDING : OPENSSL_PKCS1_PADDING;
        if ($key === false || $details === false || !openssl_sign(self::PAYLOAD, $signature, $key, OPENSSL_ALGO_SHA256, $padding)) {
            throw new RuntimeException('Cannot sign with a disposable RSA key.');
        }

        return [
            [
                'kty' => 'RSA',
                'n' => SignedDpopProof::encode($details['rsa']['n']),
                'e' => SignedDpopProof::encode($details['rsa']['e']),
            ],
            SignedDpopProof::encode($signature),
        ];
    }
}
