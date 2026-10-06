<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\AsyncSleeper;
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
        $streaming->expects(self::once())->method('resolveVideoSegmentAvailability')
            ->with($publicId, 7)
            ->willReturn(['jobId' => $jobId, 'tierKey' => '720p', 'path' => $this->expectedPath]);

        $auth = $this->createMock(StreamAuthPortInterface::class);
        $auth->expects(self::once())->method('validateUrl')
            ->with('/api/transcode/' . $publicId . '/segment?index=7', 'valid-signature', 2000000000)
            ->willReturn(true);
        $availability = $this->createMock(SegmentAvailabilityInterface::class);
        $availability->expects(self::once())->method('isReady')->with($jobId, '720p', 7)
            ->willReturn(match ($hint) {
                'stale' => $this->stalePath,
                'matching' => $this->expectedPath,
                default => null,
            });
        $controller = new StreamSegmentController($streaming, new SignedStreamRequest($auth), $availability, new AsyncSleeper());
        $request = Request::create('https://baander.app/api/transcode/' . $publicId . '/segment?index=7&sig=valid-signature&exp=2000000000');

        $response = $controller->segment($publicId->toString(), $request);

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
