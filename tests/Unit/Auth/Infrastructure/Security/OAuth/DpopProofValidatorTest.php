<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Fixtures\Auth\LeadingZeroDpopKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DpopProofValidatorTest extends TestCase
{
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
}
