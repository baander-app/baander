<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Shared\Application\Http\PublicOrigins;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\LeadingZeroDpopKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DpopProofValidatorTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';
    private const string LOGOUT_URI = 'https://baander.app/api/auth/logout';
    private const string ACCESS_TOKEN = 'disposable-access-token';

    public function testSignedProofWithLeadingZeroCoordinateIsValid(): void
    {
        $proof = new SignedDpopProof(LeadingZeroDpopKey::PEM);
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($this->once())->method('isReplay')->willReturn(false);
        $validator = new DpopProofValidator($cache, new PublicOrigins(self::ORIGIN));
        $token = 'disposable-access-token';
        $uri = 'https://baander.app/api/auth/me';
        $result = $validator->validate($proof->create('GET', $uri, $token), Request::create($uri), $token, $proof->thumbprint());

        self::assertTrue($result->isValid(), $result->getErrorDescription() ?? '');
    }

    /**
     * The `iat` check works in whole seconds, so a proof stays acceptable to the end of the
     * second its window ends in; the record lasts one second longer than the window.
     */
    public function testTheJtiIsRecordedForTheConfiguredAcceptanceWindowAndOneSecond(): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($this->once())->method('isReplay')->with(self::isString(), 421)->willReturn(false);
        $validator = new DpopProofValidator($cache, new PublicOrigins(self::ORIGIN), jtiTtlSeconds: 420);
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
        $validator = new DpopProofValidator($cache, new PublicOrigins(self::ORIGIN));
        $token = 'disposable-access-token';
        $uri = 'https://baander.app/api/auth/me';

        $this->expectExceptionObject($outage);
        $validator->validate($proof->create('GET', $uri, $token), Request::create($uri), $token, $proof->thumbprint());
    }

    /** With the caller's leave the outage skips the jti step alone; the result says the replay went unchecked. */
    public function testAStoreOutageIsToleratedWhenTheCallerAllowsSkippingTheReplayCheck(): void
    {
        $proof = new SignedDpopProof();
        $validator = new DpopProofValidator($this->refusedStore(), new PublicOrigins(self::ORIGIN));

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
            static fn (SignedDpopProof $key): array => [$key->create('POST', self::LOGOUT_URI, self::ACCESS_TOKEN, issuedAt: time() - 3600), Request::create(self::LOGOUT_URI, 'POST'), self::ACCESS_TOKEN, $key->thumbprint()],
            'DPoP proof "iat" is outside acceptable clock skew.',
        ];
    }

    /** @param callable(SignedDpopProof): array{string, Request, string, string} $arrange */
    #[DataProvider('degradedRefusals')]
    public function testADegradedProofIsStillRefusedByEveryOtherCheck(callable $arrange, string $expectedDescription): void
    {
        $key = new SignedDpopProof();
        [$proof, $request, $accessToken, $expectedJkt] = $arrange($key);
        $validator = new DpopProofValidator($this->refusedStore(), new PublicOrigins(self::ORIGIN));

        $result = $validator->validate($proof, $request, $accessToken, $expectedJkt, skipReplayCheckOnStoreFailure: true);

        self::assertFalse($result->isValid());
        self::assertSame($expectedDescription, $result->getErrorDescription());
        self::assertFalse($result->isReplayUnchecked());
    }

    public function testADegradedProofWithoutAthIsRefusedWhileAnAccessTokenIsPresented(): void
    {
        $proof = new SignedDpopProof();
        $validator = new DpopProofValidator($this->refusedStore(), new PublicOrigins(self::ORIGIN));

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
        $validator = new DpopProofValidator($cache, new PublicOrigins(self::ORIGIN));

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
        $validator = new DpopProofValidator($cache, new PublicOrigins(self::ORIGIN));

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
        $validator = new DpopProofValidator($cache, new PublicOrigins(self::ORIGIN));
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
        $validator = new DpopProofValidator($cache, new PublicOrigins(self::ORIGIN));
        $token = 'disposable-access-token';
        $uri = 'https://baander.app/api/auth/me';
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => SignedDpopProof::encode("\x01"), 'y' => SignedDpopProof::encode("\x02")];
        $result = $validator->validate($proof->create('GET', $uri, $token, $jwk), Request::create($uri), $token, $proof->thumbprint());

        self::assertFalse($result->isValid());
        self::assertSame('invalid_dpop_proof', $result->getError());
    }

    /**
     * The proof's `htu` names one of the deployment's public origins and the request path,
     * whatever scheme and host the request reached the application with.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function requestUris(): iterable
    {
        yield 'matching HTTPS' => ['https://baander.app/api/auth/me', 'https://baander.app/api/auth/me', true];
        yield 'HTTPS proof on a request the proxy forwarded as HTTP' => ['http://baander.app/api/auth/me', 'https://baander.app/api/auth/me', true];
        yield 'HTTPS proof on a request to an internal host' => ['http://internal.baander.app/api/auth/me', 'https://baander.app/api/auth/me', true];
        yield 'HTTP proof for an HTTPS origin' => ['http://baander.app/api/auth/me', 'http://baander.app/api/auth/me', false];
        yield 'origin from the Host header alone' => ['https://evil.baander.app/api/auth/me', 'https://evil.baander.app/api/auth/me', false];
        yield 'extra origin' => ['http://internal.baander.app/api/auth/me', 'https://lan.baander.app:8443/api/auth/me', true];
        yield 'extra origin off the passkey relying party' => ['http://127.0.0.1:9501/api/auth/me', 'http://127.0.0.1:9501/api/auth/me', true];
        yield 'request query ignored' => ['https://baander.app/api/auth/me?page=2', 'https://baander.app/api/auth/me', true];
        yield 'request fragment ignored' => ['https://baander.app/api/auth/me#profile', 'https://baander.app/api/auth/me', true];
        yield 'different path' => ['https://baander.app/api/auth/me', 'https://baander.app/api/favorites/', false];
    }

    #[DataProvider('requestUris')]
    public function testSignedProofBindsToAPublicOriginAndTheRequestPath(string $requestUri, string $proofUri, bool $accepted): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($accepted ? $this->once() : $this->never())->method('isReplay')->willReturn(false);
        $validator = new DpopProofValidator($cache, new PublicOrigins('https://baander.app', ['https://lan.baander.app:8443', 'http://127.0.0.1:9501']));
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

    public function testConfiguredOriginsAreNormalized(): void
    {
        $proof = new SignedDpopProof();
        $cache = $this->createStub(DpopJtiCacheInterface::class);
        $validator = new DpopProofValidator($cache, new PublicOrigins(' HTTPS://Baander.App:443/app ', ['', 'http://LAN.baander.app:80']));
        $accessToken = 'disposable-access-token';

        foreach (['https://baander.app/api/auth/me', 'http://lan.baander.app/api/auth/me'] as $proofUri) {
            $result = $validator->validate($proof->create('GET', $proofUri, $accessToken), Request::create('http://internal.baander.app/api/auth/me'), $accessToken, $proof->thumbprint());

            self::assertTrue($result->isValid(), $proofUri . ': ' . ($result->getErrorDescription() ?? ''));
        }
    }

    public function testAnOriginWithoutSchemeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DpopProofValidator($this->createStub(DpopJtiCacheInterface::class), new PublicOrigins('https://baander.app', ['lan.baander.app:8443']));
    }

    /** A jti store that raises the outage signal on every call, as a refused Redis does. */
    private function refusedStore(): DpopJtiCacheInterface
    {
        $cache = $this->createStub(DpopJtiCacheInterface::class);
        $cache->method('isReplay')->willThrowException(DpopStoreUnavailableException::jtiStore(new \RedisException('Connection refused')));

        return $cache;
    }
}
