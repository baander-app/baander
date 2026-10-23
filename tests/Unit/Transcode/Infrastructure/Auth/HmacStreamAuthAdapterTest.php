<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Auth;

use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Interface\Security\SignedStreamRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class HmacStreamAuthAdapterTest extends TestCase
{
    public function testAbsoluteExpiryAndDomainPrefixPreserveTheSignedResource(): void
    {
        $signer = new HmacStreamAuthAdapter('expiry-secret', 'https://media.baander.app/');
        $path = '/api/transcode/job/tracks/video/segment.m4s?index=7&generation=3';
        $expiresAt = 2000000000;
        $signed = $signer->signUrlUntil($path, $expiresAt);
        $signature = hash_hmac('sha256', $path.':'.$expiresAt, 'expiry-secret');
        self::assertSame($expiresAt, $signed['exp']);
        self::assertSame($signature, $signed['sig']);
        self::assertSame('https://media.baander.app'.$path.'&sig='.$signature.'&exp='.$expiresAt, $signed['url']);
        $request = Request::create($signed['url']);
        $validator = new SignedStreamRequest($signer);
        self::assertSame($expiresAt, $validator->validate($request));
        $request->query->set('exp', (string) ($expiresAt + 1));
        self::assertFalse($validator->isValid($request));
    }

    public function testSigningAPastDeadlineDoesNotRenewIt(): void
    {
        $signer = new HmacStreamAuthAdapter('expiry-secret');
        $expiresAt = time() - 10;
        $signed = $signer->signUrlUntil('/api/transcode/job/subtitles/s2/full.vtt?generation=3', $expiresAt);
        self::assertSame($expiresAt, $signed['exp']);
        $validator = new SignedStreamRequest($signer);
        self::assertFalse($validator->isValid(Request::create($signed['url'])));
        $this->expectException(AccessDeniedHttpException::class);
        $validator->validate(Request::create($signed['url']));
    }

    public function testRelativeSigningRetainsItsRequestedLifetime(): void
    {
        $signer = new HmacStreamAuthAdapter('expiry-secret');
        $before = time();
        $signed = $signer->signUrl('/api/transcode/video/master.m3u8?set=job.3', 120);
        $after = time();
        self::assertGreaterThanOrEqual($before + 120, $signed['exp']);
        self::assertLessThanOrEqual($after + 120, $signed['exp']);
        self::assertSame($signed['exp'], (new SignedStreamRequest($signer))->validate(Request::create($signed['url'])));
    }
}
