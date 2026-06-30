<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Domain\Model\OAuth\ValueObject\DpopValidationResult;
use DateTimeImmutable;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as ES256;
use Lcobucci\JWT\Signer\Ecdsa\Sha384 as ES384;
use Lcobucci\JWT\Signer\Ecdsa\Sha512 as ES512;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RS256;
use Lcobucci\JWT\Signer\Rsa\Sha384 as RS384;
use Lcobucci\JWT\Signer\Rsa\Sha512 as RS512;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final class DpopProofValidator
{
    private const SUPPORTED_ALGORITHMS = [
        'EdDSA',
        'ES256',
        'ES384',
        'ES512',
        'RS256',
        'RS384',
        'RS512',
    ];

    private const PRIVATE_KEY_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi'];

    private Parser $parser;

    /** @var array<string, Signer> */
    private array $signers;

    public function __construct(
        private readonly DpopJtiCacheInterface $jtiCache,
        private readonly int $clockSkewSeconds = 60,
        private readonly int $jtiTtlSeconds = 3600,
        private readonly LoggerInterface $logger = new NullLogger(),
    )
    {
        $this->parser = new Parser(new JoseEncoder());

        $this->signers = [
            'EdDSA' => new Eddsa(),
            'ES256' => new ES256(),
            'ES384' => new ES384(),
            'ES512' => new ES512(),
            'RS256' => new RS256(),
            'RS384' => new RS384(),
            'RS512' => new RS512(),
        ];
    }

    /**
     * Check if a DPoP proof JWT contains a nonce claim.
     *
     * Used by token endpoints to implement the challenge-response nonce flow:
     * if no nonce is present, return a `use_dpop_nonce` error with a fresh nonce.
     */
    public function extractNonce(string $dpopJwt): ?string
    {
        try {
            $token = $this->parser->parse($dpopJwt);
        } catch (Throwable) {
            return null;
        }

        if (!($token instanceof Plain)) {
            return null;
        }

        $nonce = $token->claims()->get('nonce');

        return is_string($nonce) ? $nonce : null;
    }

    public function validate(
        string $dpopJwt,
        Request $request,
        ?string $accessToken = null,
        ?string $expectedJkt = null,
    ): DpopValidationResult
    {
        // 1. Parse the JWT (structural check)
        try {
            $token = $this->parser->parse($dpopJwt);
        } catch (Throwable $e) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof is not a well-formed JWT.');
        }

        if (!($token instanceof Plain)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof is not a plain (JWS) JWT.');
        }

        // 2. typ = "dpop+jwt" (RFC 9449 §11.5)
        $typ = $token->headers()->get('typ');
        if ($typ !== 'dpop+jwt') {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof must have typ "dpop+jwt".');
        }

        // 3. alg is asymmetric, not none, supported (RFC 9449 §11.6)
        $alg = $token->headers()->get('alg');
        if ($alg === null || !is_string($alg)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof must have an "alg" header.');
        }
        if ($alg === 'none' || !in_array($alg, self::SUPPORTED_ALGORITHMS, true)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', sprintf('Unsupported algorithm "%s".', $alg));
        }

        // 4. jwk header present (public key)
        $jwk = $token->headers()->get('jwk');
        if (!is_array($jwk)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof must contain a "jwk" header.');
        }

        // 5. No private key in jwk
        foreach (self::PRIVATE_KEY_MEMBERS as $member) {
            if (array_key_exists($member, $jwk)) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof jwk must not contain private key material.');
            }
        }

        // 6. Required claims: jti, htm, htu, iat
        $claims = $token->claims();
        foreach (['jti', 'htm', 'htu', 'iat'] as $required) {
            if ($claims->get($required) === null) {
                return DpopValidationResult::invalid('invalid_dpop_proof', sprintf('DPoP proof missing required claim "%s".', $required));
            }
        }

        $jti = $claims->get('jti');
        if (!is_string($jti)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "jti" claim must be a string.');
        }

        // 7. iat within clock skew
        $iat = $claims->get('iat');
        if ($iat instanceof DateTimeImmutable) {
            $now = new DateTimeImmutable();
            $min = (int)$now->getTimestamp() - $this->clockSkewSeconds;
            $max = (int)$now->getTimestamp() + $this->clockSkewSeconds;
            $iatTs = (int)$iat->getTimestamp();

            if ($iatTs < $min || $iatTs > $max) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "iat" is outside acceptable clock skew.');
            }
        }

        // 9. htm matches request method
        $htm = $claims->get('htm');
        if (!is_string($htm) || strtolower($htm) !== strtolower($request->getMethod())) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "htm" does not match request method.');
        }

        // 10. htu matches request URI (no query/fragment, scheme normalization)
        $htu = $claims->get('htu');
        if (!is_string($htu)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "htu" must be a string.');
        }

        $expectedHtu = $this->buildHtu($request);
        if ($htu !== $expectedHtu) {
            $this->logger->debug('DPoP HTU mismatch', [
                'provided' => $htu,
                'expected' => $expectedHtu,
                'request_uri' => $request->getUri(),
                'scheme_and_http_host' => $request->getSchemeAndHttpHost(),
                'path_info' => $request->getPathInfo(),
            ]);

            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "htu" does not match request URI.');
        }

        // 11. Signature verification
        $signer = $this->signers[$alg] ?? null;
        if ($signer === null) {
            return DpopValidationResult::invalid('invalid_dpop_proof', sprintf('No signer available for algorithm "%s".', $alg));
        }

        try {
            $verificationKey = $this->jwkToVerificationKey($jwk, $alg);
        } catch (Throwable $e) {
            $this->logger->debug('DPoP proof jwk to key conversion failed', ['exception' => $e]);

            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof jwk is invalid or unsupported.');
        }

        if (!$signer->verify($this->base64urlDecode($token->signature()->toString()), $token->payload(), $verificationKey)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof signature verification failed.');
        }

        // 11. jti replay prevention (RFC 9449 §11.1) — checked after signature verification
        // to prevent poisoning the cache with JTIs from unsigned/forged proofs.
        if ($this->jtiCache->isReplay($jti)) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "jti" has been reused.');
        }

        // 12. If access token present: ath claim and jkt matching
        if ($accessToken !== null) {
            $ath = $claims->get('ath');
            if (!is_string($ath) || $ath !== $this->hashAccessToken($accessToken)) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof "ath" does not match access token.');
            }
        }

        if ($expectedJkt !== null) {
            try {
                $proofJkt = DpopJwkThumbprint::compute($jwk);
            } catch (Throwable $e) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'Failed to compute JWK thumbprint.');
            }

            if (!hash_equals($expectedJkt, $proofJkt)) {
                return DpopValidationResult::invalid('invalid_dpop_proof', 'DPoP proof jwk thumbprint does not match token binding.');
            }
        }

        // Compute jkt from the proof's jwk for the caller
        try {
            $jkt = DpopJwkThumbprint::compute($jwk);
        } catch (Throwable $e) {
            return DpopValidationResult::invalid('invalid_dpop_proof', 'Failed to compute JWK thumbprint.');
        }

        // Store jti to prevent replay
        $this->jtiCache->store($jti, $this->jtiTtlSeconds);

        return DpopValidationResult::valid($jkt);
    }

    private function buildHtu(Request $request): string
    {
        $uri = $request->getSchemeAndHttpHost() . $request->getPathInfo();

        // RFC 9449 §4.3: http and https on the same host are equivalent
        $uri = preg_replace('#^https?://#i', 'https://', $uri);

        return $uri;
    }

    /**
     * Converts a JWK public key to a key suitable for lcobucci/jwt verification.
     *
     * @param array<string, mixed> $jwk
     */
    private function jwkToVerificationKey(array $jwk, string $alg): InMemory
    {
        $kty = $jwk['kty'] ?? '';

        if ($kty === 'OKP') {
            return $this->jwkOkpToKey($jwk);
        }

        if ($kty === 'EC') {
            return $this->jwkEcToPem($jwk);
        }

        if ($kty === 'RSA') {
            return $this->jwkRsaToPem($jwk);
        }

        throw new InvalidArgumentException(sprintf('Unsupported JWK key type: "%s".', $kty));
    }

    private function jwkOkpToKey(array $jwk): InMemory
    {
        // EdDSA with Ed25519: sodium expects raw 32-byte public key
        $x = $jwk['x'] ?? '';
        if ($x === '') {
            throw new InvalidArgumentException('OKP JWK missing "x" parameter.');
        }

        // Decode base64url to raw bytes
        $keyBytes = $this->base64urlDecode($x);

        return InMemory::plainText($keyBytes);
    }

    private function base64urlDecode(string $input): string
    {
        $padded = strtr($input, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode($padded);
    }

    private function jwkEcToPem(array $jwk): InMemory
    {
        $crv = $jwk['crv'] ?? '';
        $x = $this->base64urlDecode($jwk['x'] ?? '');
        $y = $this->base64urlDecode($jwk['y'] ?? '');

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
        $algoIdSeq = "\x30" . $this->encodeLength(strlen($algoId)) . $algoId;

        // BIT STRING with uncompressed point (0x04 prefix + x + y)
        $pointData = "\x04" . $x . $y;
        $bitString = "\x03" . $this->encodeLength(strlen($pointData) + 1) . "\x00" . $pointData;

        // SubjectPublicKeyInfo = SEQUENCE { AlgorithmIdentifier, BIT STRING }
        $spki = $algoIdSeq . $bitString;
        $der = "\x30" . $this->encodeLength(strlen($spki)) . $spki;

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";

        return InMemory::plainText($pem);
    }

    private function jwkRsaToPem(array $jwk): InMemory
    {
        $n = $this->base64urlDecode($jwk['n'] ?? '');
        $e = $this->base64urlDecode($jwk['e'] ?? '');

        // RSA public key DER: SEQUENCE { SEQUENCE { OID rsaEncryption, NULL }, BIT STRING { SEQUENCE { n, e } } }
        $rsaEncryptionOid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        $nInt = "\x02" . $this->encodeLength(strlen($n)) . $n;
        $eInt = "\x02" . $this->encodeLength(strlen($e)) . $e;
        $rsaSeq = "\x30" . $this->encodeLength(strlen($nInt) + strlen($eInt)) . $nInt . $eInt;
        $bitString = "\x03" . $this->encodeLength(strlen($rsaSeq) + 1) . "\x00" . $rsaSeq;
        $outerSeq = "\x30" . $this->encodeLength(strlen($rsaEncryptionOid) + strlen($bitString)) . $rsaEncryptionOid . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($outerSeq), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";

        return InMemory::plainText($pem);
    }

    private function encodeLength(int $length): string
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

        return chr(0x80 | count($bytes)) . implode('', array_map('chr', $bytes));
    }

    private function hashAccessToken(string $accessToken): string
    {
        return $this->base64urlEncode(hash('sha256', $accessToken, binary: true));
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
