<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Catalog\Domain\Model\Video;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Interface\Controller\StreamSigningController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class StreamSigningSecurityTest extends TestCase
{
    #[DataProvider('invalidRequests')]
    public function testInvalidSigningRequestsNeverStartPlayback(string $body): void
    {
        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects($this->never())->method('signUrl');
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->never())->method('start');
        $controller = new StreamSigningController($auth, $playback, $this->videos(null), $this->createStub(TranscodeStreamingPortInterface::class));
        self::assertSame(400, $controller->sign(Request::create('/', 'POST', content: $body))->getStatusCode());
    }

    /** @return iterable<array{string}> */
    public static function invalidRequests(): iterable
    {
        yield ['{'];
        yield ['null'];
        yield ['{"path":"/api/admin"}'];
        yield ['{"path":"https://evil.baander.app/path"}'];
        yield ['{"path":[]}'];
        yield ['{"path":"/api/transcode/00000000-0000-4000-8000-000000000001/master.m3u8"}'];
        $path = '/api/transcode/0123456789abABCD_-xyz/master.m3u8';
        foreach ([0, -1, 86401, '60', 1.5, null, []] as $duration) {
            yield [json_encode(['path' => $path, 'expiresInSeconds' => $duration], JSON_THROW_ON_ERROR)];
        }
        yield [json_encode(['path' => $path.'?unexpected=1'], JSON_THROW_ON_ERROR)];
    }

    public function testSigningCannotBypassLibraryAccess(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $id = $video->getId();
        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects($this->never())->method('signUrl');
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->once())->method('start')->with($this->equalTo($id))
            ->willThrowException(new AccessDeniedException());
        $controller = new StreamSigningController($auth, $playback, $this->videos($video), $this->streaming());
        $this->expectException(AccessDeniedException::class);
        $controller->sign(Request::create('/', 'POST', content: json_encode(['path' => '/api/transcode/'.$video->getPublicId().'/master.m3u8'], JSON_THROW_ON_ERROR)));
    }

    public function testSigningDefaultsTo24Hours(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $id = $video->getId();
        $path = '/api/transcode/'.$video->getPublicId().'/master.m3u8';
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->once())->method('start')->with($this->equalTo($id));
        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects($this->once())->method('signUrl')->with($path.'?generation=3&job=0123456789abABCD_-xyz', 86400)
            ->willReturn(['url' => $path.'?sig=s&exp=1', 'sig' => 's', 'exp' => 1]);
        $controller = new StreamSigningController($auth, $playback, $this->videos($video), $this->streaming());
        self::assertSame(200, $controller->sign(Request::create('/', 'POST', content: json_encode(['path' => $path], JSON_THROW_ON_ERROR)))->getStatusCode());
    }
    public function testRefreshChecksAccessWithoutAdmittingAgain(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->never())->method('start');
        $playback->expects($this->once())->method('assertAccess')->with($video->getId());
        $auth = $this->createStub(StreamAuthPortInterface::class);
        $auth->method('signUrl')->willReturn(['url' => '/signed', 'sig' => 's', 'exp' => 1]);
        $controller = new StreamSigningController($auth, $playback, $this->videos($video), $this->streaming());
        $response = $controller->sign(Request::create('/', 'POST', content: json_encode(['path' => '/api/transcode/'.$video->getPublicId().'/master.m3u8', 'refresh' => true], JSON_THROW_ON_ERROR)));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(3, $body['generation']);
        self::assertTrue($body['ready']);
        self::assertSame('paused', $body['status']);
    }

    private function streaming(): TranscodeStreamingPortInterface
    {
        $streaming = $this->createStub(TranscodeStreamingPortInterface::class);
        $streaming->method('getPlaybackState')->willReturn(['generation' => 3, 'jobId' => '0123456789abABCD_-xyz', 'sessionId' => 'session', 'revision' => 5, 'ready' => true, 'status' => 'paused', 'position' => 24.0]);
        return $streaming;
    }

    private function videos(?Video $video): TranscodePublicViewInterface
    {
        $videos = $this->createStub(TranscodePublicViewInterface::class);
        if ($video !== null) {
            $videos->method('videoId')->willReturn($video->getId());
        }

        return $videos;
    }

}
