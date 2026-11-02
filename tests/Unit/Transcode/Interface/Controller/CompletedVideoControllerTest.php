<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\CompletedVideoDeliveryPort;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Interface\Controller\CompletedVideoController;
use App\Transcode\Interface\Security\SignedStreamRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CompletedVideoControllerTest extends TestCase
{
    private const string JOB = '0123456789abcdefghijk';
    private const int EXPIRY = 2000000000;

    #[DataProvider('invalidSignatures')]
    public function testInvalidSignatureNeverReachesDelivery(string $method, string $kind): void
    {
        $delivery = $this->createMock(CompletedVideoDeliveryPort::class);
        foreach (['grant', 'master', 'playlist', 'path'] as $operation) {
            $delivery->expects(self::never())->method($operation);
        }
        $path = $this->path($method);
        $signer = new HmacStreamAuthAdapter('test-secret');
        $url = match ($kind) {
            'missing' => $path,
            'expired' => $signer->signUrl($path, -10)['url'],
            'wrong key' => (new HmacStreamAuthAdapter('wrong-secret'))->signUrl($path)['url'],
            default => $signer->signUrl($path)['url'].'&extra=1',
        };
        $controller = new CompletedVideoController($delivery, new SignedStreamRequest($signer));

        $this->expectException(AccessDeniedHttpException::class);
        $this->invoke($controller, $method, Request::create('https://baander.app'.$url));
    }

    #[DataProvider('manifests')]
    public function testManifestForwardsPinnedIdentityAndAbsoluteExpiry(string $method, string $operation): void
    {
        $delivery = $this->createMock(CompletedVideoDeliveryPort::class);
        $expected = $delivery->expects(self::once())->method($operation);
        if ($operation === 'master') {
            $expected->with(PublicId::fromString(self::JOB), 7, self::EXPIRY);
        } else {
            $expected->with(PublicId::fromString(self::JOB), 7, MediaTrack::Audio, self::EXPIRY);
        }
        $expected->willReturn("#EXTM3U\n#EXT-X-ENDLIST\n");
        $response = $this->invoke($this->controller($delivery), $method, $this->request($method));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("#EXTM3U\n#EXT-X-ENDLIST\n", $response->getContent());
        self::assertSame('application/vnd.apple.mpegurl', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    #[DataProvider('endpoints')]
    public function testMissingArtifactReturnsNotFound(string $method): void
    {
        $delivery = $this->createStub(CompletedVideoDeliveryPort::class);
        $this->expectException(NotFoundHttpException::class);
        $this->invoke($this->controller($delivery), $method, $this->request($method));
    }

    #[DataProvider('invalidIndices')]
    public function testSegmentRejectsInvalidIndexBeforeDelivery(?string $index): void
    {
        $delivery = $this->createMock(CompletedVideoDeliveryPort::class);
        $delivery->expects(self::never())->method('path');
        $path = '/api/transcode/artifacts/'.self::JOB.'/7/tracks/audio/segment.m4s';
        if ($index !== null) {
            $path .= '?'.$index;
        }
        $this->expectException(BadRequestHttpException::class);
        $this->invoke($this->controller($delivery), 'segment', $this->signedRequest($path));
    }

    #[DataProvider('files')]
    public function testFilesForwardTrackAndIndexAndSupportRanges(string $method, ?int $index, MediaTrack $track): void
    {
        $path = tempnam(sys_get_temp_dir(), 'completed-video-');
        self::assertNotFalse($path);
        file_put_contents($path, '0123456789');
        try {
            $delivery = $this->createMock(CompletedVideoDeliveryPort::class);
            $delivery->expects(self::once())->method('path')
                ->with(PublicId::fromString(self::JOB), 7, $track, $index, self::EXPIRY)
                ->willReturn($path);
            $request = $this->signedRequest(str_replace(
                ['/audio/', 'index=0'],
                ['/'.$track->value.'/', 'index='.($index ?? 0)],
                $this->path($method),
            ));
            $request->headers->set('Range', 'bytes=2-5');
            $response = $this->invoke($this->controller($delivery), $method, $request, $track);
            self::assertInstanceOf(BinaryFileResponse::class, $response);
            $response->prepare($request);
            self::assertSame(206, $response->getStatusCode());
            self::assertSame('bytes 2-5/10', $response->headers->get('Content-Range'));
            self::assertSame($track === MediaTrack::Video ? 'video/mp4' : 'audio/mp4', $response->headers->get('Content-Type'));
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            ob_start();
            try {
                $response->sendContent();
                self::assertSame('2345', ob_get_contents());
            } finally {
                ob_end_clean();
            }
        } finally {
            unlink($path);
        }
    }

    public function testDisappearedFileReturnsNotFound(): void
    {
        $delivery = $this->createStub(CompletedVideoDeliveryPort::class);
        $delivery->method('path')->willReturn(sys_get_temp_dir().'/baander-missing-'.bin2hex(random_bytes(12)));
        $this->expectException(NotFoundHttpException::class);
        $this->invoke($this->controller($delivery), 'init', $this->request('init'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSignatures(): iterable
    {
        foreach (['master', 'media', 'init', 'segment'] as $method) {
            foreach (['missing', 'expired', 'wrong key', 'tampered'] as $kind) {
                yield "$method/$kind" => [$method, $kind];
            }
        }
    }

    /** @return iterable<array{string, string}> */
    public static function manifests(): iterable
    {
        yield ['master', 'master'];
        yield ['media', 'playlist'];
    }

    /** @return iterable<array{string}> */
    public static function endpoints(): iterable
    {
        foreach (['master', 'media', 'init', 'segment'] as $method) {
            yield [$method];
        }
    }

    /** @return iterable<array{?string}> */
    public static function invalidIndices(): iterable
    {
        foreach ([null, 'index=-1', 'index=1.5', 'index=abc', 'index%5B0%5D=0', 'index=9999999999999999999999'] as $index) {
            yield [$index];
        }
    }

    /** @return iterable<array{string, ?int, MediaTrack}> */
    public static function files(): iterable
    {
        yield ['init', null, MediaTrack::Audio];
        yield ['init', null, MediaTrack::Video];
        yield ['segment', 0, MediaTrack::Audio];
        yield ['segment', 17, MediaTrack::Video];
    }

    private function controller(CompletedVideoDeliveryPort $delivery): CompletedVideoController
    {
        return new CompletedVideoController($delivery, new SignedStreamRequest(new HmacStreamAuthAdapter('test-secret')));
    }

    private function request(string $method): Request
    {
        return $this->signedRequest($this->path($method));
    }

    private function signedRequest(string $path): Request
    {
        $url = (new HmacStreamAuthAdapter('test-secret'))->signUrlUntil($path, self::EXPIRY)['url'];
        return Request::create('https://baander.app'.$url);
    }

    private function path(string $method): string
    {
        $base = '/api/transcode/artifacts/'.self::JOB.'/7';
        return match ($method) {
            'master' => $base.'/master.m3u8',
            'media' => $base.'/tracks/audio/media.m3u8',
            'init' => $base.'/tracks/audio/init.mp4',
            default => $base.'/tracks/audio/segment.m4s?index=0',
        };
    }

    private function invoke(CompletedVideoController $controller, string $method, Request $request, MediaTrack $track = MediaTrack::Audio): Response
    {
        return $method === 'master'
            ? $controller->master(self::JOB, 7, $request)
            : $controller->$method(self::JOB, 7, $track, $request);
    }
}
