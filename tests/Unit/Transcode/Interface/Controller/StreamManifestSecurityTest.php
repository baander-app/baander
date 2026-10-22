<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Catalog\Domain\Model\Video;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Application\Port\SleeperInterface;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Interface\Controller\StreamManifestController;
use App\Transcode\Interface\Security\SignedStreamRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class StreamManifestSecurityTest extends TestCase
{
    #[DataProvider('manifests')]
    public function testManifestRequiresAnUntamperedUnexpiredSignature(string $method, string $kind): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $videoId = $video->getPublicId()->toString();
        $jobId = (new PublicId())->toString();
        $path = match ($method) {
            'masterManifest' => "/api/transcode/$videoId/master.m3u8?set=$jobId.3",
            'dashManifest' => "/api/transcode/$videoId/manifest.mpd?set=$jobId.3",
            'mediaManifest' => "/api/transcode/$jobId/tracks/video/media.m3u8?generation=3",
            default => "/api/transcode/$jobId/subtitles/en/media.m3u8",
        };
        $signer = new HmacStreamAuthAdapter('test-secret');
        $url = match ($kind) {
            'missing' => $path,
            'expired' => $signer->signUrl($path, -10)['url'],
            'wrong key' => (new HmacStreamAuthAdapter('wrong-secret'))->signUrl($path)['url'],
            'tampered' => $signer->signUrl($path)['url'].'&extra=1',
            default => $signer->signUrl($path)['url'],
        };
        $valid = $kind === 'valid';
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $serviceMethod = match ($method) {
            'masterManifest' => 'getMasterManifest', 'dashManifest' => 'getDashManifest',
            'mediaManifest' => 'getMediaManifest', default => 'getSubtitleManifest',
        };
        $streaming->expects($valid ? $this->once() : $this->never())->method($serviceMethod)->willReturn('#EXTINF:10');
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->never())->method('start');
        $videos = $this->createMock(TranscodePublicViewInterface::class);
        $videos->expects($valid && in_array($method, ['masterManifest', 'dashManifest'], true) ? $this->once() : $this->never())
            ->method('videoId')->with($video->getPublicId())->willReturn($video->getId());
        $controller = new StreamManifestController($streaming, new SignedStreamRequest($signer), $playback, $videos, $this->createStub(SleeperInterface::class));
        if (!$valid) {
            $this->expectException(AccessDeniedHttpException::class);
        }
        $request = Request::create($url);
        $response = match ($method) {
            'subtitleManifest' => $controller->subtitleManifest($jobId, 'en', $request),
            'mediaManifest' => $controller->mediaManifest($jobId, MediaTrack::Video, $request),
            default => $controller->$method($videoId, $request),
        };
        self::assertSame(200, $response->getStatusCode());
    }

    public function testUnavailableMediaManifestReturnsWithoutWaitingForAWorker(): void
    {
        $jobId = (new PublicId())->toString();
        $signer = new HmacStreamAuthAdapter('test-secret');
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects($this->once())->method('getMediaManifest')->with(self::anything(), MediaTrack::Video, 3)->willReturn('#EXTM3U');
        $controller = new StreamManifestController($streaming, new SignedStreamRequest($signer), $this->createStub(PlaybackPortInterface::class), $this->createStub(TranscodePublicViewInterface::class), $this->createStub(SleeperInterface::class));
        $response = $controller->mediaManifest($jobId, MediaTrack::Video, Request::create($signer->signUrl("/api/transcode/$jobId/tracks/video/media.m3u8?generation=3")['url']));
        self::assertSame(503, $response->getStatusCode());
    }

    #[DataProvider('reloadOutcomes')]
    public function testGrowingDashWaitsThenReadsTheSameSignedSetAgain(bool $retired): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $jobId = (new PublicId())->toString();
        $renditions = [['jobId' => $jobId, 'generation' => 3]];
        $signer = new HmacStreamAuthAdapter('test-secret');
        $state = new class { public bool $advanced = false; };
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::exactly(2))->method('getDashManifest')->with($video->getId(), $renditions)
            ->willReturnCallback(static function () use ($state, $retired): string {
                if (!$state->advanced) {
                    return '<MPD type="dynamic"><Representation id="before"/></MPD>';
                }
                return $retired ? '<MPD type="static"/>' : '<MPD type="static"><Representation id="after"/></MPD>';
            });
        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->expects(self::once())->method('sleep')->with(1.0)
            ->willReturnCallback(static function () use ($state): void { $state->advanced = true; });
        $videos = $this->createStub(TranscodePublicViewInterface::class);
        $videos->method('videoId')->willReturn($video->getId());
        $controller = new StreamManifestController($streaming, new SignedStreamRequest($signer), $this->createStub(PlaybackPortInterface::class), $videos, $sleeper);

        $response = $controller->dashManifest($video->getPublicId()->toString(), Request::create($signer->signUrl('/api/transcode/'.$video->getPublicId().'/manifest.mpd?set='.$jobId.'.3')['url']));

        self::assertSame($retired ? 503 : 200, $response->getStatusCode());
        ob_start();
        try {
            $response->sendContent();
            self::assertSame($retired ? '<MPD type="static"/>' : '<MPD type="static"><Representation id="after"/></MPD>', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function reloadOutcomes(): iterable
    {
        yield 'newly published segments' => [false];
        yield 'retired generation' => [true];
    }

    public function testGrowingDashRevalidatesSignatureAfterWaiting(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $jobId = (new PublicId())->toString();
        $state = new class { public bool $afterWait = false; };
        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects(self::exactly(2))->method('validateUrl')->willReturnCallback(static function () use ($state): bool { return !$state->afterWait; });
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::once())->method('getDashManifest')->willReturn('<MPD type="dynamic"><Representation/></MPD>');
        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->expects(self::once())->method('sleep')->with(1.0)
            ->willReturnCallback(static function () use ($state): void { $state->afterWait = true; });
        $videos = $this->createStub(TranscodePublicViewInterface::class);
        $videos->method('videoId')->willReturn($video->getId());
        $controller = new StreamManifestController($streaming, new SignedStreamRequest($auth), $this->createStub(PlaybackPortInterface::class), $videos, $sleeper);

        $this->expectException(AccessDeniedHttpException::class);
        $controller->dashManifest($video->getPublicId()->toString(), Request::create('/api/transcode/'.$video->getPublicId().'/manifest.mpd?set='.$jobId.'.3&sig=valid&exp=2000000000'));
    }

    public function testCompletedDashReturnsImmediately(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $jobId = (new PublicId())->toString();
        $signer = new HmacStreamAuthAdapter('test-secret');
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::once())->method('getDashManifest')->willReturn('<MPD type="static"><Representation/></MPD>');
        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->expects(self::never())->method('sleep');
        $videos = $this->createStub(TranscodePublicViewInterface::class);
        $videos->method('videoId')->willReturn($video->getId());
        $controller = new StreamManifestController($streaming, new SignedStreamRequest($signer), $this->createStub(PlaybackPortInterface::class), $videos, $sleeper);

        self::assertSame(200, $controller->dashManifest($video->getPublicId()->toString(), Request::create($signer->signUrl('/api/transcode/'.$video->getPublicId().'/manifest.mpd?set='.$jobId.'.3')['url']))->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function manifests(): iterable
    {
        foreach (['masterManifest', 'mediaManifest', 'dashManifest', 'subtitleManifest'] as $method) {
            foreach (['valid', 'missing', 'expired', 'wrong key', 'tampered'] as $kind) {
                yield "$method/$kind" => [$method, $kind];
            }
        }
    }
}
