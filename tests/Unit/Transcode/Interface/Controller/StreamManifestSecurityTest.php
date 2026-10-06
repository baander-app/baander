<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
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
        $videoId = (new Uuid())->toString();
        $jobId = (new PublicId())->toString();
        $path = match ($method) {
            'masterManifest' => "/api/transcode/$videoId/master.m3u8",
            'dashManifest' => "/api/transcode/$videoId/manifest.mpd",
            'mediaManifest' => "/api/transcode/$jobId/media.m3u8?tier=720p",
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
        $controller = new StreamManifestController($streaming, new SignedStreamRequest($signer), $playback, $this->createStub(SleeperInterface::class));
        if (!$valid) {
            $this->expectException(AccessDeniedHttpException::class);
        }
        $request = Request::create($url);
        $response = match ($method) {
            'subtitleManifest' => $controller->subtitleManifest($jobId, 'en', $request),
            'mediaManifest' => $controller->mediaManifest($jobId, $request),
            default => $controller->$method($videoId, $request),
        };
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMediaManifestWaitsThroughTheSleeperUntilSegmentsAreListed(): void
    {
        $jobId = (new PublicId())->toString();
        $signer = new HmacStreamAuthAdapter('test-secret');
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects($this->exactly(2))->method('getMediaManifest')
            ->willReturnOnConsecutiveCalls('#EXTM3U', "#EXTM3U\n#EXTINF:10");
        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->expects($this->once())->method('sleep')->with(0.5);
        $controller = new StreamManifestController($streaming, new SignedStreamRequest($signer), $this->createStub(PlaybackPortInterface::class), $sleeper);

        $response = $controller->mediaManifest($jobId, Request::create($signer->signUrl("/api/transcode/$jobId/media.m3u8")['url']));
        ob_start();
        $response->sendContent();

        self::assertSame("#EXTM3U\n#EXTINF:10", ob_get_clean());
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
