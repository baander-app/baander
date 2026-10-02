<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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
        $controller = new StreamManifestController($streaming, new SignedStreamRequest($signer), $playback);
        if (!$valid) {
            $this->expectException(AccessDeniedException::class);
        }
        $request = Request::create($url);
        $response = match ($method) {
            'subtitleManifest' => $controller->subtitleManifest($jobId, 'en', $request),
            'mediaManifest' => $controller->mediaManifest($jobId, $request),
            default => $controller->$method($videoId, $request),
        };
        self::assertSame(200, $response->getStatusCode());
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
