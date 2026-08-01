<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Exception\FFmpegProcessFailedException;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Swoole\ProcessSpawnerInterface;
use App\Transcode\Infrastructure\Swoole\TranscodeStreamManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for TranscodeStreamManager lifecycle and concurrency enforcement.
 *
 * Uses a StubSpawner that records calls without spawning real FFmpeg, so
 * the tests are fast and hermetic.
 */
final class TranscodeStreamManagerTest extends TestCase
{
    private SegmentAvailabilityInterface $availability;
    private TranscodeStoragePortInterface $storage;
    private SegmentEncoder $segmentEncoder;
    private StubSpawner $spawner;
    private TranscodeStreamManager $manager;

    protected function setUp(): void
    {
        $this->availability = $this->createMock(SegmentAvailabilityInterface::class);
        $this->storage = $this->createStub(TranscodeStoragePortInterface::class);

        // SegmentEncoder is final — create a real instance with stub deps
        $ffmpeg = $this->createStub(FFmpegPortInterface::class);
        $encoderStorage = $this->createStub(TranscodeStoragePortInterface::class);
        $this->segmentEncoder = new SegmentEncoder(
            ffmpeg: $ffmpeg,
            storage: $encoderStorage,
            encoderProfile: EncoderProfile::software('libx265'),
        );

        // Storage returns a temp dir for resolveJobDirectory
        $this->storage->method('resolveJobDirectory')->willReturnCallback(
            fn () => sys_get_temp_dir() . '/stream-test-' . uniqid('', true),
        );

        $this->spawner = new StubSpawner();

        $this->manager = new TranscodeStreamManager(
            availability: $this->availability,
            storage: $this->storage,
            segmentEncoder: $this->segmentEncoder,
            logger: new NullLogger(),
            spawner: $this->spawner,
            maxConcurrentStreams: 3,
        );
    }

    public function testStartStreamSpawnsProcessAndReturnsOutputDir(): void
    {
        $job = $this->makeJob();

        $outputDir = $this->manager->startStream(
            $job,
            '/videos/source.mkv',
            QualityTier::p720(),
            'scale=-2:720',
        );

        self::assertNotEmpty($outputDir);
        self::assertTrue($this->manager->isStreaming($job->getId()));
        self::assertSame(1, $this->spawner->spawnCount);
    }

    public function testStartStreamIdempotentReturnsExistingDir(): void
    {
        $job = $this->makeJob();

        $dir1 = $this->manager->startStream($job, '/src.mkv', QualityTier::p720(), '');
        $dir2 = $this->manager->startStream($job, '/src.mkv', QualityTier::p720(), '');

        self::assertSame($dir1, $dir2);
        self::assertSame(1, $this->spawner->spawnCount, 'Should not re-spawn for same job');
    }

    public function testStopStreamKillsProcessAndClearsState(): void
    {
        $job = $this->makeJob();

        $this->manager->startStream($job, '/src.mkv', QualityTier::p720(), '');
        self::assertTrue($this->manager->isStreaming($job->getId()));

        $this->manager->stopStream($job->getId());
        self::assertFalse($this->manager->isStreaming($job->getId()));
        self::assertNotEmpty($this->spawner->signals, 'Should have sent a kill signal');
    }

    public function testStopStreamNoOpIfNotRunning(): void
    {
        $jobId = Uuid::v4();

        // Should not throw
        $this->manager->stopStream($jobId);
        self::assertFalse($this->manager->isStreaming($jobId));
    }

    public function testPauseStreamSendsSigstop(): void
    {
        $job = $this->makeJob();
        $this->manager->startStream($job, '/src.mkv', QualityTier::p720(), '');

        $this->spawner->running = true;
        $this->manager->pauseStream($job->getId());

        self::assertContains(19, $this->spawner->signals, 'Should send SIGSTOP (19)');
    }

    public function testResumeStreamSendsSigcont(): void
    {
        $job = $this->makeJob();
        $this->manager->startStream($job, '/src.mkv', QualityTier::p720(), '');

        $this->manager->resumeStream($job->getId());

        self::assertContains(18, $this->spawner->signals, 'Should send SIGCONT (18)');
    }

    public function testPauseNoOpIfNotRunning(): void
    {
        $this->manager->pauseStream(Uuid::v4());
        self::assertEmpty($this->spawner->signals);
    }

    public function testEnforceConcurrencyRejectsAtCapacity(): void
    {
        $jobs = [];
        for ($i = 0; $i < 3; $i++) {
            $jobs[$i] = $this->makeJob();
            $this->manager->startStream($jobs[$i], '/src.mkv', QualityTier::p720(), '');
        }

        self::assertSame(3, $this->manager->getActiveStreamCount());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum concurrent streams');

        $jobs[3] = $this->makeJob();
        $this->manager->startStream($jobs[3], '/src.mkv', QualityTier::p720(), '');
    }

    public function testStopAllKillsAllStreams(): void
    {
        $jobs = [];
        for ($i = 0; $i < 2; $i++) {
            $jobs[$i] = $this->makeJob();
            $this->manager->startStream($jobs[$i], '/src.mkv', QualityTier::p720(), '');
        }

        $this->manager->stopAll();

        self::assertSame(0, $this->manager->getActiveStreamCount());
        foreach ($jobs as $job) {
            self::assertFalse($this->manager->isStreaming($job->getId()));
        }
    }

    public function testPollOnceReturnsFalseForUnknownJob(): void
    {
        self::assertFalse($this->manager->pollOnce(Uuid::v4()));
    }

    public function testPollOnceReturnsRunningStateFromSpawner(): void
    {
        $job = $this->makeJob();
        $outputDir = $this->manager->startStream($job, '/src.mkv', QualityTier::p720(), '');
        // Produce a segment so the exit is treated as normal completion.
        file_put_contents($outputDir . '/v0_a0_720p_0.m4s', str_repeat('x', 1024));

        $this->spawner->running = true;
        self::assertTrue($this->manager->pollOnce($job->getId()));

        $this->spawner->running = false;
        self::assertFalse($this->manager->pollOnce($job->getId()));
    }

    public function testPollOnceThrowsWhenProcessExitsWithoutOutput(): void
    {
        $job = $this->makeJob();
        $this->manager->startStream($job, '/src.mkv', QualityTier::p720(), '');

        $this->spawner->running = true;
        self::assertTrue($this->manager->pollOnce($job->getId()));

        // Simulate FFmpeg dying instantly with an error on stderr.
        $this->spawner->running = false;
        $this->spawner->exitCode = 1;

        try {
            $this->manager->pollOnce($job->getId());
            self::fail('Expected FFmpegProcessFailedException');
        } catch (FFmpegProcessFailedException $e) {
            self::assertSame(1, $e->getExitCode());
        }
    }

    public function testPollOnceMarksNewSegmentsAvailable(): void
    {
        $job = $this->makeJob();
        $outputDir = sys_get_temp_dir() . '/stream-avail-test-' . uniqid('', true);
        @mkdir($outputDir, 0755, true);

        // Create a fake muxed segment file (identity-encoded name)
        $segPath = $outputDir . '/v0_a0_720p_5.m4s';
        file_put_contents($segPath, str_repeat('x', 1024));

        // Override storage to return our dir
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn($outputDir);

        $manager = new TranscodeStreamManager(
            availability: $this->availability,
            storage: $storage,
            segmentEncoder: $this->segmentEncoder,
            logger: new NullLogger(),
            spawner: $this->spawner,
        );

        $this->spawner->running = true;
        $manager->startStream($job, '/src.mkv', QualityTier::p720(), '');

        $this->availability
            ->expects(self::once())
            ->method('markReady')
            ->with($job->getId(), '720p', 5, $segPath);

        $manager->pollOnce($job->getId());

        // Clean up
        @unlink($segPath);
        @rmdir($outputDir);
    }

    public function testPollOnceDoesNotRemarkAlreadyMarkedSegments(): void
    {
        $job = $this->makeJob();
        $outputDir = sys_get_temp_dir() . '/stream-remark-test-' . uniqid('', true);
        @mkdir($outputDir, 0755, true);

        $segPath = $outputDir . '/v0_a0_720p_0.m4s';
        file_put_contents($segPath, str_repeat('x', 512));

        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn($outputDir);

        $manager = new TranscodeStreamManager(
            availability: $this->availability,
            storage: $storage,
            segmentEncoder: $this->segmentEncoder,
            logger: new NullLogger(),
            spawner: $this->spawner,
        );

        $this->spawner->running = true;
        $manager->startStream($job, '/src.mkv', QualityTier::p720(), '');

        $this->availability->expects(self::once())->method('markReady');

        // Poll twice — markReady should only fire once
        $manager->pollOnce($job->getId());
        $manager->pollOnce($job->getId());

        @unlink($segPath);
        @rmdir($outputDir);
    }

    private function makeJob(): TranscodeJob
    {
        return TranscodeJob::create(
            videoId: Uuid::v4(),
            qualityTier: QualityTier::p720(),
            outputDirectory: '/tmp/test-job',
        );
    }
}

/**
 * Stub process spawner that records calls without spawning real processes.
 */
final class StubSpawner implements ProcessSpawnerInterface
{
    public int $spawnCount = 0;
    public bool $running = true;
    public int $exitCode = 0;
    /** @var array<int, int> */
    public array $signals = [];

    /** @var list<string>|null */
    public ?array $lastCommand = null;

    public function spawn(array $command): array
    {
        $this->spawnCount++;
        $this->lastCommand = $command;

        return [
            'resource' => new \stdClass(),
            'pipes' => [],
            'pid' => 10000 + $this->spawnCount,
        ];
    }

    public function isRunning(mixed $resource): bool
    {
        return $this->running;
    }

    public function exitCode(mixed $resource): int
    {
        return $this->exitCode;
    }

    public function signal(int $pid, int $signal): void
    {
        $this->signals[] = $signal;
    }

    public function close(mixed $resource): void
    {
        // no-op
    }
}
