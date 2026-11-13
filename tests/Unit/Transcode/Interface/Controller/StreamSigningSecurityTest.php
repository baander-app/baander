<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Catalog\Domain\Model\Video;
use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\DTO\PlaybackEndReason;
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
    private const string REQUEST_ID = '019a4321-7321-7000-8000-000000000001';

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
        yield [json_encode(['path' => $path], JSON_THROW_ON_ERROR)];
        yield [json_encode(['path' => $path, 'refresh' => true], JSON_THROW_ON_ERROR)];
        foreach (['', 'another-client', null, [], 42] as $requestId) {
            yield [json_encode(['path' => $path, 'requestId' => $requestId], JSON_THROW_ON_ERROR)];
        }
        foreach ([[], null, 'job', ['bad-id'], [42], array_fill(0, 4, '0123456789abABCD_-xyz')] as $jobs) {
            yield [json_encode(['path' => $path, 'requestId' => self::REQUEST_ID, 'refresh' => true, 'jobIds' => $jobs], JSON_THROW_ON_ERROR)];
        }
        foreach ([0, -1, 86401, '60', 1.5, null, []] as $duration) {
            yield [json_encode(['requestId' => self::REQUEST_ID, 'path' => $path, 'expiresInSeconds' => $duration], JSON_THROW_ON_ERROR)];
        }
        yield [json_encode(['requestId' => self::REQUEST_ID, 'path' => $path.'?unexpected=1'], JSON_THROW_ON_ERROR)];
        // An attached rendition set belongs to a refresh and must be a canonical set.
        yield [json_encode(['requestId' => self::REQUEST_ID, 'path' => $path, 'attachedSet' => '0123456789abABCD_-xyz.3'], JSON_THROW_ON_ERROR)];
        foreach (['', 'job', '0123456789abABCD_-xyz', '0123456789abABCD_-xyz.0', '0123456789abABCD_-xyz.3,0123456789abABCD_-xyz.3', 'zzzzzzzzzzzzzzzzzzzzz.1,0123456789abABCD_-xyz.3', 3, ['0123456789abABCD_-xyz.3']] as $attached) {
            yield [json_encode(['requestId' => self::REQUEST_ID, 'path' => $path, 'refresh' => true, 'jobIds' => ['0123456789abABCD_-xyz'], 'attachedSet' => $attached], JSON_THROW_ON_ERROR)];
        }
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
        $controller->sign(Request::create('/', 'POST', content: json_encode(['requestId' => self::REQUEST_ID, 'path' => '/api/transcode/'.$video->getPublicId().'/master.m3u8'], JSON_THROW_ON_ERROR)));
    }

    public function testSigningDefaultsTo24Hours(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $id = $video->getId();
        $path = '/api/transcode/'.$video->getPublicId().'/master.m3u8';
        $playback = $this->createMock(PlaybackPortInterface::class);
        $session = new PublicId();
        $playback->expects($this->once())->method('start')->with($this->equalTo($id), self::REQUEST_ID)
            ->willReturn(['0123456789abABCD_-xyz' => $session]);
        $playback->expects(self::never())->method('findSessions');
        $playback->expects(self::once())->method('touchSessions')->with([$session]);
        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects($this->once())->method('signUrl')->with($path.'?set=0123456789abABCD_-xyz.3', 86400)
            ->willReturn(['url' => $path.'?sig=s&exp=1', 'sig' => 's', 'exp' => 1]);
        $controller = new StreamSigningController($auth, $playback, $this->videos($video), $this->streaming());
        self::assertSame(200, $controller->sign(Request::create('/', 'POST', content: json_encode(['requestId' => self::REQUEST_ID, 'path' => $path], JSON_THROW_ON_ERROR)))->getStatusCode());
    }
    public function testRefreshChecksAccessWithoutAdmittingAgain(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects($this->never())->method('start');
        $playback->expects($this->once())->method('assertAccess')->with($video->getId());
        $playback->expects(self::once())->method('findSessions')->with(self::anything(), self::REQUEST_ID, [PublicId::fromString('0123456789abABCD_-xyz')])->willReturn(['0123456789abABCD_-xyz' => $session = new PublicId()]);
        // A paused player sends no playback reports; its signing is the session's sign of life.
        $playback->expects(self::once())->method('touchSessions')->with([$session]);
        $auth = $this->createStub(StreamAuthPortInterface::class);
        $auth->method('signUrl')->willReturn(['url' => '/signed', 'sig' => 's', 'exp' => 1]);
        $controller = new StreamSigningController($auth, $playback, $this->videos($video), $this->streaming());
        $response = $controller->sign(Request::create('/', 'POST', content: json_encode(['requestId' => self::REQUEST_ID, 'path' => '/api/transcode/'.$video->getPublicId().'/master.m3u8', 'refresh' => true, 'jobIds' => ['0123456789abABCD_-xyz']], JSON_THROW_ON_ERROR)));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::REQUEST_ID, $body['requestId']);
        self::assertSame(['0123456789abABCD_-xyz'], $body['jobIds']);
        self::assertSame(3, $body['generation']);
        self::assertTrue($body['ready']);
        self::assertSame('paused', $body['status']);
    }

    public function testAttachedSetCountsOnlyForJobsTheSessionsHold(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $owned = '0123456789abABCD_-xyz';
        $foreign = 'ABCDEFGHIJKLMNOPQRSTU';
        $playback = $this->createStub(PlaybackPortInterface::class);
        $playback->method('findSessions')->willReturn([$owned => new PublicId()]);
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::once())->method('getPlaybackState')
            ->with($video->getId(), [$owned], [['jobId' => $owned, 'generation' => 3]])
            ->willReturn(['generation' => 3, 'jobId' => $owned, 'revision' => 5, 'ready' => false, 'status' => 'running', 'position' => 0.0, 'startTime' => null, 'duration' => null, 'renditionSet' => '']);
        $controller = new StreamSigningController($this->createStub(StreamAuthPortInterface::class), $playback, $this->videos($video), $streaming);
        $response = $controller->sign(Request::create('/', 'POST', content: json_encode(['requestId' => self::REQUEST_ID, 'path' => '/api/transcode/'.$video->getPublicId().'/master.m3u8',
            'refresh' => true, 'jobIds' => [$owned], 'attachedSet' => $owned.'.3,'.$foreign.'.9'], JSON_THROW_ON_ERROR)));
        self::assertSame(200, $response->getStatusCode());
    }

    /** @return iterable<string, array{PlaybackEndReason}> */
    public static function endReasons(): iterable
    {
        foreach (PlaybackEndReason::cases() as $reason) {
            yield $reason->value => [$reason];
        }
    }

    #[DataProvider('endReasons')]
    public function testRefreshNamingOnlyEndedSessionsIsGoneWithItsReasonAndReadsNoRuntimeState(PlaybackEndReason $reason): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::never())->method('getPlaybackState');
        $jobs = [PublicId::fromString('0123456789abABCD_-xyz')];
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects(self::once())->method('findSessions')->with($video->getId(), self::REQUEST_ID, $jobs)->willReturn([]);
        $playback->expects(self::once())->method('endReason')->with($video->getId(), self::REQUEST_ID, $jobs)->willReturn($reason);
        $playback->expects(self::never())->method('touchSessions');
        $playback->expects(self::never())->method('start');
        $controller = new StreamSigningController($this->createStub(StreamAuthPortInterface::class), $playback, $this->videos($video), $streaming);
        $response = $controller->sign(Request::create('/', 'POST', content: json_encode(['requestId' => self::REQUEST_ID, 'path' => '/api/transcode/'.$video->getPublicId().'/master.m3u8', 'refresh' => true, 'jobIds' => ['0123456789abABCD_-xyz']], JSON_THROW_ON_ERROR)));
        // A definite answer: nothing invites the client to retry the same sessions.
        self::assertSame(410, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($reason->value, $body['reason']);
        foreach (['position', 'status', 'sessionId', 'jobId'] as $field) { self::assertArrayNotHasKey($field, $body); }
    }

    public function testStartWithoutCapacityStaysRetryable(): void
    {
        $video = Video::create('/video.mp4', 'hash');
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::never())->method('getPlaybackState');
        $playback = $this->createMock(PlaybackPortInterface::class);
        $playback->expects(self::once())->method('start')->willReturn([]);
        $playback->expects(self::never())->method('endReason');
        $controller = new StreamSigningController($this->createStub(StreamAuthPortInterface::class), $playback, $this->videos($video), $streaming);
        $response = $controller->sign(Request::create('/', 'POST', content: json_encode(['requestId' => self::REQUEST_ID, 'path' => '/api/transcode/'.$video->getPublicId().'/master.m3u8'], JSON_THROW_ON_ERROR)));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('Retry-After'));
    }

    private function streaming(): TranscodeStreamingPortInterface
    {
        $streaming = $this->createStub(TranscodeStreamingPortInterface::class);
        $streaming->method('getPlaybackState')->willReturn(['generation' => 3, 'jobId' => '0123456789abABCD_-xyz', 'sessionId' => 'session', 'revision' => 5, 'ready' => true, 'status' => 'paused', 'position' => 24.0, 'startTime' => 0.0, 'duration' => 100.0, 'renditionSet' => '0123456789abABCD_-xyz.3']);
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
