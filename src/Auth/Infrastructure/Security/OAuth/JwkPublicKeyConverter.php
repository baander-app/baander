<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use InvalidArgumentException;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as ES256;
use Lcobucci\JWT\Signer\Ecdsa\Sha384 as ES384;
use Lcobucci\JWT\Signer\Ecdsa\Sha512 as ES512;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RS256;
use Lcobucci\JWT\Signer\Rsa\Sha384 as RS384;
use Lcobucci\JWT\Signer\Rsa\Sha512 as RS512;

/**
 * Verifies JWS signatures made with a public JWK, for DPoP proofs and client assertions.
 *
 * Only asymmetric algorithms are supported; `none` and the HMAC algorithms never are,
 * so a public key can never be used as an HMAC secret. `DpopProofValidator` and
 * `ClientAssertionVerifier` each keep the list of algorithms they accept.
 *
 * Every verification runs in OpenSSL or libsodium. An RSA key has a modulus of 2048 to
 * 4096 bits and the public exponent 65537, whichever RSA algorithm it verifies.
 */
final class JwkPublicKeyConverter
{
    /** JWK members that carry private key material (RFC 7518 section 6). */
    public const array PRIVATE_KEY_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi'];

    private const int MIN_RSA_MODULUS_BYTES = 256;
    private const int MAX_RSA_MODULUS_BYTES = 512;

    /** 65537 as the bytes a JWK `e` member decodes to. */
    private const string RSA_PUBLIC_EXPONENT = "\x01\x00\x01";

    public static function signer(string $alg): ?Signer
    {
        return match ($alg) {
            'EdDSA' => new Eddsa(),
            'ES256' => new ES256(),
            'ES384' => new ES384(),
            'ES512' => new ES512(),
            'RS256' => new RS256(),
            'RS384' => new RS384(),
            'RS512' => new RS512(),
            default => null,
        };
    }

    /**
     * Whether the base64url-encoded signature over the payload verifies with the JWK.
     *
     * @param array<string, mixed> $jwk
     * @throws InvalidArgumentException when the JWK is malformed, of an unsupported type, or an RSA key outside the bounds
     */
    public static function verify(array $jwk, string $alg, string $payload, string $encodedSignature): bool
    {
        // lcobucci/jwt has no RSA-PSS signer, so PS256 goes to OpenSSL directly.
        if ($alg === 'PS256') {
            return self::verifyRsaPss($jwk, $payload, self::base64urlDecode($encodedSignature));
        }

        $signer = self::signer($alg) ?? throw new InvalidArgumentException(sprintf('Unsupported algorithm "%s".', $alg));

        return $signer->verify(self::base64urlDecode($encodedSignature), $payload, self::toVerificationKey($jwk));
    }

    /**
     * RSASSA-PSS with SHA-256 and MGF1 over SHA-256 (RFC 7518 section 3.5). OpenSSL reads the
     * salt length off the signature, so a salt of another length than the 32 bytes the RFC
     * fixes verifies too; forging one takes the private key all the same.
     *
     * @param array<string, mixed> $jwk
     * @throws InvalidArgumentException when the JWK is not a usable RSA public key
     */
    private static function verifyRsaPss(array $jwk, string $payload, string $signature): bool
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            throw new InvalidArgumentException('PS256 needs an RSA key.');
        }

        return openssl_verify($payload, $signature, self::rsaToPem($jwk)->contents(), OPENSSL_ALGO_SHA256, OPENSSL_PKCS1_PSS_PADDING) === 1;
    }

    /**
     * Converts a JWK public key to a key suitable for lcobucci/jwt verification.
     *
     * @param array<string, mixed> $jwk
     * @throws InvalidArgumentException when the JWK is malformed, of an unsupported type, or an RSA key outside the bounds
     */
    public static function toVerificationKey(array $jwk): InMemory
    {
        $kty = $jwk['kty'] ?? '';

        return match ($kty) {
            'OKP' => self::okpToKey($jwk),
            'EC' => self::ecToPem($jwk),
            'RSA' => self::rsaToPem($jwk),
            default => throw new InvalidArgumentException(sprintf('Unsupported JWK key type: "%s".', is_string($kty) ? $kty : get_debug_type($kty))),
        };
    }

    public static function base64urlDecode(string $input): string
    {
        $padded = strtr($input, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode($padded);
    }

    /** @param array<string, mixed> $jwk */
    private static function okpToKey(array $jwk): InMemory
    {
        // EdDSA with Ed25519: sodium expects the raw 32-byte public key.
        $crv = self::member($jwk, 'crv');
        if ($crv !== 'Ed25519') {
            throw new InvalidArgumentException(sprintf('Unsupported OKP curve: "%s"; only Ed25519 signs.', $crv));
        }
        $x = self::member($jwk, 'x');
        if ($x === '') {
            throw new InvalidArgumentException('OKP JWK missing "x" parameter.');
        }

        return InMemory::plainText(self::base64urlDecode($x));
    }

    /** @param array<string, mixed> $jwk */
    private static function ecToPem(array $jwk): InMemory
    {
        $crv = self::member($jwk, 'crv');
        $x = self::base64urlDecode(self::member($jwk, 'x'));
        $y = self::base64urlDecode(self::member($jwk, 'y'));

        $curveOid = match ($crv) {
            'P-256' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",
            'P-384' => "\x06\x05\x2b\x81\x04\x00\x22",
            'P-521' => "\x06\x05\x2b\x81\x04\x00\x23",
            default => throw new InvalidArgumentException(sprintf('Unsupported EC curve: "%s".', $crv)),
        };

        // ecPublicKey OID: 1.2.840.10045.2.1
        $ecPublicKeyOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";

        // AlgorithmIdentifier = SEQUENCE { ecPublicKey OID, curve OID }
        $algoId = $ecPublicKeyOid . $curveOid;
        $algoIdSeq = "\x30" . self::encodeLength(strlen($algoId)) . $algoId;

        // BIT STRING with uncompressed point (0x04 prefix + x + y)
        $pointData = "\x04" . $x . $y;
        $bitString = "\x03" . self::encodeLength(strlen($pointData) + 1) . "\x00" . $pointData;

        // SubjectPublicKeyInfo = SEQUENCE { AlgorithmIdentifier, BIT STRING }
        $spki = $algoIdSeq . $bitString;

        return self::pem("\x30" . self::encodeLength(strlen($spki)) . $spki);
    }

    /**
     * A verification raises the signature to the power `e` modulo `n`, and whoever presents
     * the key chose both numbers. The bounds fix the cost of that before OpenSSL sees the key.
     *
     * @param array<string, mixed> $jwk
     * @throws InvalidArgumentException when the modulus or the public exponent is outside the bounds
     */
    private static function rsaToPem(array $jwk): InMemory
    {
        $n = self::unsignedInteger($jwk, 'n');
        $e = self::unsignedInteger($jwk, 'e');
        if (strlen($n) < self::MIN_RSA_MODULUS_BYTES) {
            throw new InvalidArgumentException('RSA keys must be at least 2048 bits.');
        }
        if (strlen($n) > self::MAX_RSA_MODULUS_BYTES) {
            throw new InvalidArgumentException('RSA keys must be at most 4096 bits.');
        }
        if ($e !== self::RSA_PUBLIC_EXPONENT) {
            throw new InvalidArgumentException('RSA keys must use the public exponent 65537.');
        }

        // RSA public key DER: SEQUENCE { SEQUENCE { OID rsaEncryption, NULL }, BIT STRING { SEQUENCE { n, e } } }
        $rsaEncryptionOid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        $nInt = "\x02" . self::encodeLength(strlen($n)) . $n;
        $eInt = "\x02" . self::encodeLength(strlen($e)) . $e;
        $rsaSeq = "\x30" . self::encodeLength(strlen($nInt) + strlen($eInt)) . $nInt . $eInt;
        $bitString = "\x03" . self::encodeLength(strlen($rsaSeq) + 1) . "\x00" . $rsaSeq;

        return self::pem("\x30" . self::encodeLength(strlen($rsaEncryptionOid) + strlen($bitString)) . $rsaEncryptionOid . $bitString);
    }

    private static function pem(string $der): InMemory
    {
        return InMemory::plainText("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
    }

    /**
     * The big-endian bytes of a JWK integer member, without leading zero bytes.
     *
     * @param array<string, mixed> $jwk
     */
    private static function unsignedInteger(array $jwk, string $name): string
    {
        return ltrim(self::base64urlDecode(self::member($jwk, $name)), "\0");
    }

    /** @param array<string, mixed> $jwk */
    private static function member(array $jwk, string $name): string
    {
        $value = $jwk[$name] ?? '';

        return is_string($value) ? $value : throw new InvalidArgumentException(sprintf('JWK member "%s" must be a string.', $name));
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = [];
        $temp = $length;
        while ($temp > 0) {
            array_unshift($bytes, $temp & 0xff);
            $temp >>= 8;
        }

        return chr(0x80 | count($bytes)) . implode('', array_map(chr(...), $bytes));
    }
}
