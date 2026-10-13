<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\LeadingZeroDpopKey;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DpopProofValidatorTest extends TestCase
{
    private const string LOGOUT_URI = 'https://baander.app/api/auth/logout';
    private const string ACCESS_TOKEN = 'disposable-access-token';

    public function testSignedProofWithLeadingZeroCoordinateIsValid(): void
    {
        $proof = new SignedDpopProof(LeadingZeroDpopKey::PEM);
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($this->once())->method('isReplay')->willReturn(false);
        $validator = new DpopProofValidator($cache);
        $token = 'disposable-access-token';
        $uri = 'https://baander.app/api/auth/me';
        $result = $validator->validate($proof->create('GET', $uri, $token), Request::create($uri), $token, $proof->thumbprint());

        self::assertTrue($result->isValid(), $result->getErrorDescription() ?? '');
    }

    public function testTheJtiIsRecordedForTheConfiguredAcceptanceWindow(): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($this->once())->method('isReplay')->with(self::isString(), 420)->willReturn(false);
        $validator = new DpopProofValidator($cache, jtiTtlSeconds: 420);
        $token = 'disposable-access-token';
        $uri = 'https://baander.app/api/auth/me';
        $result = $validator->validate($proof->create('GET', $uri, $token), Request::create($uri), $token, $proof->thumbprint());

        self::assertTrue($result->isValid(), $result->getErrorDescription() ?? '');
    }

    /** Without the caller's leave the store outage propagates, so the route answers 503. */
    public function testAStoreOutageLeavesValidateUnchanged(): void
    {
        $proof = new SignedDpopProof();
        $outage = DpopStoreUnavailableException::jtiStore(new \RedisException('Connection refused'));
        $cache = $this->createStub(DpopJtiCacheInterface::class);
        $cache->method('isReplay')->willThrowException($outage);
        $validator = new DpopProofValidator($cache);
        $token = 'disposable-access-token';
        $uri = 'https://baander.app/api/auth/me';

        $this->expectExceptionObject($outage);
        $validator->validate($proof->create('GET', $uri, $token), Request::create($uri), $token, $proof->thumbprint());
    }

    /** With the caller's leave the outage skips the jti step alone; the result says the replay went unchecked. */
    public function testAStoreOutageIsToleratedWhenTheCallerAllowsSkippingTheReplayCheck(): void
    {
        $proof = new SignedDpopProof();
        $validator = new DpopProofValidator($this->refusedStore());

        $result = $validator->validate(
            $proof->create('POST', self::LOGOUT_URI, self::ACCESS_TOKEN),
            Request::create(self::LOGOUT_URI, 'POST'),
            self::ACCESS_TOKEN,
            $proof->thumbprint(),
            skipReplayCheckOnStoreFailure: true,
        );

        self::assertTrue($result->isValid(), $result->getErrorDescription() ?? '');
        self::assertTrue($result->isReplayUnchecked());
        self::assertSame($proof->thumbprint(), $result->getJkt());
    }

    /**
     * Every check but the replay check still binds the proof on the degraded path; the
     * `ath` and `jkt` checks sit after the jti step and run even though it was skipped.
     *
     * @return iterable<string, array{callable(SignedDpopProof): array{string, Request, string, string}, string}>
     */
    public static function degradedRefusals(): iterable
    {
        yield 'mismatched ath' => [
            static fn (SignedDpopProof $key): array => [$key->create('POST', self::LOGOUT_URI, 'another-access-token'), Request::create(self::LOGOUT_URI, 'POST'), self::ACCESS_TOKEN, $key->thumbprint()],
            'DPoP proof "ath" does not match access token.',
        ];
        yield 'wrong key' => [
            static fn (SignedDpopProof $key): array => [$key->create('POST', self::LOGOUT_URI, self::ACCESS_TOKEN), Request::create(self::LOGOUT_URI, 'POST'), self::ACCESS_TOKEN, (new SignedDpopProof())->thumbprint()],
            'DPoP proof jwk thumbprint does not match token binding.',
        ];
        yield 'wrong htm' => [
            static fn (SignedDpopProof $key): array => [$key->create('GET', self::LOGOUT_URI, self::ACCESS_TOKEN), Request::create(self::LOGOUT_URI, 'POST'), self::ACCESS_TOKEN, $key->thumbprint()],
            'DPoP proof "htm" does not match request method.',
        ];
        yield 'wrong htu' => [
            static fn (SignedDpopProof $key): array => [$key->create('POST', 'https://baander.app/api/auth/me', self::ACCESS_TOKEN), Request::create(self::LOGOUT_URI, 'POST'), self::ACCESS_TOKEN, $key->thumbprint()],
            'DPoP proof "htu" does not match request URI.',
        ];
        yield 'iat outside the window' => [
            static fn (SignedDpopProof $key): array => [self::staleProof(), Request::create(self::LOGOUT_URI, 'POST'), self::ACCESS_TOKEN, $key->thumbprint()],
            'DPoP proof "iat" is outside acceptable clock skew.',
        ];
    }

    /** @param callable(SignedDpopProof): array{string, Request, string, string} $arrange */
    #[DataProvider('degradedRefusals')]
    public function testADegradedProofIsStillRefusedByEveryOtherCheck(callable $arrange, string $expectedDescription): void
    {
        $key = new SignedDpopProof();
        [$proof, $request, $accessToken, $expectedJkt] = $arrange($key);
        $validator = new DpopProofValidator($this->refusedStore());

        $result = $validator->validate($proof, $request, $accessToken, $expectedJkt, skipReplayCheckOnStoreFailure: true);

        self::assertFalse($result->isValid());
        self::assertSame($expectedDescription, $result->getErrorDescription());
        self::assertFalse($result->isReplayUnchecked());
    }

    public function testADegradedProofWithoutAthIsRefusedWhileAnAccessTokenIsPresented(): void
    {
        $proof = new SignedDpopProof();
        $validator = new DpopProofValidator($this->refusedStore());

        $result = $validator->validate(
            $proof->createWithNonce('POST', self::LOGOUT_URI, 'server-nonce'),
            Request::create(self::LOGOUT_URI, 'POST'),
            self::ACCESS_TOKEN,
            $proof->thumbprint(),
            skipReplayCheckOnStoreFailure: true,
        );

        self::assertFalse($result->isValid());
        self::assertSame('DPoP proof "ath" does not match access token.', $result->getErrorDescription());
    }

    /** A working store's replay verdict is untouched by the caller's leave. */
    public function testAReplayDetectedByAWorkingStoreIsRefusedEvenWhenSkippingIsAllowed(): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createStub(DpopJtiCacheInterface::class);
        $cache->method('isReplay')->willReturn(true);
        $validator = new DpopProofValidator($cache);

        $result = $validator->validate(
            $proof->create('POST', self::LOGOUT_URI, self::ACCESS_TOKEN),
            Request::create(self::LOGOUT_URI, 'POST'),
            self::ACCESS_TOKEN,
            $proof->thumbprint(),
            skipReplayCheckOnStoreFailure: true,
        );

        self::assertFalse($result->isValid());
        self::assertSame('DPoP proof "jti" has been reused.', $result->getErrorDescription());
        self::assertFalse($result->isReplayUnchecked());
    }

    public function testAWorkingStoreThatHasNotSeenTheJtiSetsNoFlagEvenWhenSkippingIsAllowed(): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($this->once())->method('isReplay')->willReturn(false);
        $validator = new DpopProofValidator($cache);

        $result = $validator->validate(
            $proof->create('POST', self::LOGOUT_URI, self::ACCESS_TOKEN),
            Request::create(self::LOGOUT_URI, 'POST'),
            self::ACCESS_TOKEN,
            $proof->thumbprint(),
            skipReplayCheckOnStoreFailure: true,
        );

        self::assertTrue($result->isValid(), $result->getErrorDescription() ?? '');
        self::assertFalse($result->isReplayUnchecked());
    }

    public function testAJwkCarryingPrivateMembersIsRejectedBeforeTheReplayCheck(): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($this->never())->method('isReplay');
        $validator = new DpopProofValidator($cache);
        $token = 'disposable-access-token';
        $uri = 'https://baander.app/api/auth/me';
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => SignedDpopProof::encode(str_repeat("\x01", 32)), 'y' => SignedDpopProof::encode(str_repeat("\x02", 32)), 'd' => SignedDpopProof::encode(str_repeat("\x03", 32))];
        $result = $validator->validate($proof->create('GET', $uri, $token, $jwk), Request::create($uri), $token, $proof->thumbprint());

        self::assertFalse($result->isValid());
        self::assertSame('DPoP proof jwk must not contain private key material.', $result->getErrorDescription());
    }

    public function testMalformedEcPointReturnsInvalidProofWithoutPoisoningReplayCache(): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($this->never())->method('isReplay');
        $validator = new DpopProofValidator($cache);
        $token = 'disposable-access-token';
        $uri = 'https://baander.app/api/auth/me';
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => SignedDpopProof::encode("\x01"), 'y' => SignedDpopProof::encode("\x02")];
        $result = $validator->validate($proof->create('GET', $uri, $token, $jwk), Request::create($uri), $token, $proof->thumbprint());

        self::assertFalse($result->isValid());
        self::assertSame('invalid_dpop_proof', $result->getError());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function requestUris(): iterable
    {
        yield 'matching HTTPS' => ['https://baander.app/api/auth/me', 'https://baander.app/api/auth/me', true];
        yield 'matching HTTP' => ['http://baander.app/api/auth/me', 'http://baander.app/api/auth/me', true];
        yield 'HTTPS proof on HTTP request' => ['http://baander.app/api/auth/me', 'https://baander.app/api/auth/me', false];
        yield 'HTTP proof on HTTPS request' => ['https://baander.app/api/auth/me', 'http://baander.app/api/auth/me', false];
        yield 'request query ignored' => ['https://baander.app/api/auth/me?page=2', 'https://baander.app/api/auth/me', true];
        yield 'request fragment ignored' => ['https://baander.app/api/auth/me#profile', 'https://baander.app/api/auth/me', true];
        yield 'different host' => ['https://api.baander.app/api/auth/me', 'https://baander.app/api/auth/me', false];
        yield 'different path' => ['https://baander.app/api/auth/me', 'https://baander.app/api/favorites/', false];
    }

    #[DataProvider('requestUris')]
    public function testSignedProofBindsToActualRequestUri(string $requestUri, string $proofUri, bool $accepted): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($accepted ? $this->once() : $this->never())->method('isReplay')->willReturn(false);
        $validator = new DpopProofValidator($cache);
        $accessToken = 'disposable-access-token';
        $result = $validator->validate(
            $proof->create('GET', $proofUri, $accessToken),
            Request::create($requestUri),
            $accessToken,
            $proof->thumbprint(),
        );

        self::assertSame($accepted, $result->isValid(), $result->getErrorDescription() ?? '');
        if (!$accepted) {
            self::assertSame('DPoP proof "htu" does not match request URI.', $result->getErrorDescription());
        }
    }

    /** A jti store that raises the outage signal on every call, as a refused Redis does. */
    private function refusedStore(): DpopJtiCacheInterface
    {
        $cache = $this->createStub(DpopJtiCacheInterface::class);
        $cache->method('isReplay')->willThrowException(DpopStoreUnavailableException::jtiStore(new \RedisException('Connection refused')));

        return $cache;
    }

    /**
     * A logout proof for the test's access token issued an hour ago, signed by a key of its
     * own; the fixture signs its proofs now, and the `iat` check runs before the key binding.
     */
    private static function staleProof(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => SignedDpopProof::encode(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => SignedDpopProof::encode(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ];
        $body = SignedDpopProof::encode(json_encode(['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $jwk], JSON_THROW_ON_ERROR)) . '.'
            . SignedDpopProof::encode(json_encode([
                'jti' => bin2hex(random_bytes(16)),
                'htm' => 'POST',
                'htu' => self::LOGOUT_URI,
                'iat' => time() - 3600,
                'ath' => SignedDpopProof::encode(hash('sha256', self::ACCESS_TOKEN, true)),
            ], JSON_THROW_ON_ERROR));

        return $body . '.' . SignedDpopProof::encode((new Sha256())->sign($body, InMemory::plainText($pem)));
    }
}
