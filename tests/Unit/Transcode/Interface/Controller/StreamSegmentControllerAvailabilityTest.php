<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\AsyncSleeper;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Interface\Controller\StreamSegmentController;
use App\Transcode\Interface\Security\SignedStreamRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamSegmentControllerAvailabilityTest extends TestCase
{
    private string $directory;
    private string $expectedPath;
    private string $stalePath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-segment-hint-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->expectedPath = $this->directory . '/expected.m4s';
        $this->stalePath = $this->directory . '/old-attempt.m4s';
        file_put_contents($this->expectedPath, 'expected-attempt-bytes');
        file_put_contents($this->stalePath, 'stale-attempt-bytes');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    #[DataProvider('readinessHints')]
    public function testSegmentStreamsOnlyTheResolvedExpectedPath(string $hint): void
    {
        $jobId = Uuid::generate();
        $publicId = new PublicId();
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::atLeast(2))->method('resolveSegmentAvailability')
            ->with($publicId, MediaTrack::Video, 7, 3)
            ->willReturn(['jobId' => $jobId, 'tierKey' => '720p', 'path' => $this->expectedPath]);

        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects(self::once())->method('validateUrl')
            ->with('/api/transcode/' . $publicId . '/tracks/video/segment.m4s?index=7&generation=3', 'valid-signature', 2000000000)
            ->willReturn(true);
        $availability = $this->createMock(SegmentAvailabilityInterface::class);
        $availability->expects(self::once())->method('isReady')->with($jobId, '720p', 7)
            ->willReturn(match ($hint) {
                'stale' => $this->stalePath,
                'matching' => $this->expectedPath,
                default => null,
            });
        $controller = new StreamSegmentController($streaming, new SignedStreamRequest($auth), $availability, new AsyncSleeper());
        $request = Request::create('https://baander.app/api/transcode/' . $publicId . '/tracks/video/segment.m4s?index=7&generation=3&sig=valid-signature&exp=2000000000');

        $response = $controller->segment($publicId->toString(), MediaTrack::Video, $request);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        ob_start();
        try {
            $response->sendContent();
            self::assertSame('expected-attempt-bytes', ob_get_contents());
            self::assertSame((string) strlen('expected-attempt-bytes'), $response->headers->get('Content-Length'));
        } finally {
            ob_end_clean();
        }
    }

    public function testAudioTrackUsesItsOwnAvailabilityKeyAndMimeType(): void
    {
        $jobId = Uuid::generate();
        $publicId = new PublicId();
        $tierKey = '720p/generation-3/audio';
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::atLeast(2))->method('resolveSegmentAvailability')
            ->with($publicId, MediaTrack::Audio, 7, 3)
            ->willReturn(['jobId' => $jobId, 'tierKey' => $tierKey, 'path' => $this->expectedPath]);
        $auth = $this->createStub(StreamAuthPortInterface::class);
        $auth->method('validateUrl')->willReturn(true);
        $availability = $this->createMock(SegmentAvailabilityInterface::class);
        $availability->expects(self::once())->method('isReady')->with($jobId, $tierKey, 7)->willReturn($this->expectedPath);
        $controller = new StreamSegmentController($streaming, new SignedStreamRequest($auth), $availability, new AsyncSleeper());

        $response = $controller->segment($publicId->toString(), MediaTrack::Audio, Request::create('https://baander.app/api/transcode/'.$publicId.'/tracks/audio/segment.m4s?index=7&generation=3&sig=valid&exp=2000000000'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('audio/mp4', $response->headers->get('Content-Type'));
    }

    public function testChangingTheSignedTrackCannotResolveMedia(): void
    {
        $publicId = new PublicId();
        $signer = new \App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter('test-secret');
        $signed = $signer->signUrl('/api/transcode/'.$publicId.'/tracks/video/segment.m4s?index=7&generation=3')['url'];
        $streaming = $this->createMock(TranscodeStreamingPortInterface::class);
        $streaming->expects(self::never())->method('resolveSegmentAvailability');
        $controller = new StreamSegmentController($streaming, new SignedStreamRequest($signer), $this->createStub(SegmentAvailabilityInterface::class), new AsyncSleeper());

        $response = $controller->segment($publicId->toString(), MediaTrack::Audio, Request::create(str_replace('/tracks/video/', '/tracks/audio/', $signed)));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testGenerationRetiredDuringFileWaitNeverStreamsItsBytes(): void
    {
        $publicId = new PublicId();
        $resolved = ['jobId' => Uuid::generate(), 'tierKey' => '720p/generation-3', 'path' => $this->expectedPath];
        $streaming = $this->createStub(TranscodeStreamingPortInterface::class);
        $state = new class { public bool $current = true; };
        $streaming->method('resolveSegmentAvailability')->willReturnCallback(function () use ($state, $resolved): ?array { return $state->current ? $resolved : null; });
        $auth = $this->createStub(StreamAuthPortInterface::class);
        $auth->method('validateUrl')->willReturn(true);
        $sleeper = $this->createStub(\App\Shared\Application\Port\SleeperInterface::class);
        $sleeper->method('sleep')->willReturnCallback(function () use ($state): void { $state->current = false; });
        $controller = new StreamSegmentController($streaming, new SignedStreamRequest($auth), $this->createStub(SegmentAvailabilityInterface::class), $sleeper);
        $request = Request::create('https://baander.app/api/transcode/'.$publicId.'/tracks/video/segment.m4s?index=7&generation=3&sig=valid&exp=2000000000');
        $response = $controller->segment($publicId->toString(), MediaTrack::Video, $request);
        self::assertSame(503, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function readinessHints(): iterable
    {
        yield 'stale readiness path' => ['stale'];
        yield 'matching readiness path' => ['matching'];
        yield 'no readiness hint' => ['none'];
    }

    public function testMissingExpectedPathCannotBeReplacedByAStaleHint(): void
    {
        unlink($this->expectedPath);
        $jobId = Uuid::generate();
        $availability = $this->createMock(SegmentAvailabilityInterface::class);
        $availability->expects(self::once())->method('isReady')->with($jobId, '720p', 7)
            ->willReturn($this->stalePath);
        $controller = new StreamSegmentController(
            $this->createStub(TranscodeStreamingPortInterface::class),
            new SignedStreamRequest($this->createStub(StreamAuthPortInterface::class)),
            $availability,
            new AsyncSleeper(),
        );

        $wait = new \ReflectionMethod(StreamSegmentController::class, 'waitForSegment');

        self::assertNull($wait->invoke($controller, $jobId, '720p', 7, $this->expectedPath, 0));
    }
}
