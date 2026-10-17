<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\DTO\TranscodeExecutionClaim;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Swoole\LoopLockRenewalTimerInterface;
use App\Transcode\Infrastructure\Swoole\ProcessSpawnerInterface;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use App\Transcode\Infrastructure\Swoole\TranscodeStreamManager;
use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class TranscodeWorkerExecutionTest extends TestCase
{
    private string $root;
    private TranscodeJob $job;
    private TranscodeSession $session;
    private ?TranscodeExecutionClaim $current;
    private TranscodeSessionSubscriber $worker;
    private VideoExecutionSpawner $spawner;
    /** @var list<string> */
    private array $observed = [];
    private int $allocations = 0;
    private int $releases = 0;
    private int $savesAfterLoss = 0;
    private ?Closure $onDispatch = null;
    private VideoExecutionRenewalTimer $timer;
    private bool $renewAllowed = true;
    private ?Closure $onFence = null;
    private float $sourceDuration = 6.0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-worker-video-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        file_put_contents($this->root . '/source', 'source');
        $video = Video::create($this->root . '/source', 'video-test');
        $this->job = TranscodeJob::create($video->getId(), QualityTier::p720(), $this->root);
        $this->session = TranscodeSession::create(Uuid::generate(), $this->job->getId(), $video->getId(), AudioProfile::streamingStereo());
        $this->current = $this->claim(1, 1);
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByUuid')->willReturnCallback(fn() => TranscodeJob::reconstitute(clone $this->job->getState()));
        $jobs->method('save')->willReturnCallback(function (TranscodeJob $job): void {
            $this->job = $job;
            if ($this->current === null) {
                ++$this->savesAfterLoss;
            }
        });
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('findByUuid')->willReturnCallback(fn() => $this->session);
        $videos = $this->createStub(VideoRepositoryInterface::class);
        $videos->method('findByUuid')->willReturn($video);
        $pool = $this->createStub(CpuProcessPoolInterface::class);
        $pool->method('isRunning')->willReturn(true);
        $pool->method('dispatch')->willReturnCallback(function (): void { ($this->onDispatch)?->__invoke(); });
        $pool->method('readResult')->willReturnCallback(fn(): array => ['status' => 'ok', 'data' => json_encode([
            'format' => ['duration' => $this->sourceDuration], 'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720]],
        ], JSON_THROW_ON_ERROR)]);
        $ffmpeg = $this->createStub(FFmpegPortInterface::class);
        $ffmpeg->method('probeVideo')->willReturn(VideoProbeResult::fromProbeOutput([
            'format' => ['duration' => 6.0],
            'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720]],
        ]));
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('refresh')->willReturnCallback(fn() => $this->current);
        $ownership->method('withOwnership')->willReturnCallback(function (TranscodeExecutionClaim $claim, callable $operation): bool {
            ($this->onFence)?->__invoke();
            if (!$this->claimMatches($claim)) {
                return false;
            }
            $operation();
            return true;
        });
        $ownership->method('observe')->willReturnCallback(function (TranscodeExecutionClaim $claim, string $outcome): bool {
            if (!$this->claimMatches($claim)) {
                return false;
            }
            $this->observed[] = $outcome;
            return true;
        });
        $ownership->method('release')->willReturnCallback(function (TranscodeExecutionClaim $claim): bool {
            if ($this->claimMatches($claim)) {
                ++$this->releases;
                return true;
            }
            return false;
        });
        $control = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $control->method('renew')->willReturnCallback(fn(): bool => $this->renewAllowed);
        $this->timer = new VideoExecutionRenewalTimer();
        $admission = $this->createStub(StreamAdmissionPortInterface::class);
        $admission->method('admit')->willReturnCallback(function (): void { ++$this->allocations; });
        $admission->method('complete')->willReturnCallback(function (): void { --$this->allocations; });
        $storage = new TranscodeFileStorage(new SegmentFileResolver($this->root));
        $encoder = new SegmentEncoder($ffmpeg, $storage, EncoderProfile::software('libx264'));
        $this->spawner = new VideoExecutionSpawner();
        $streams = new TranscodeStreamManager($this->createStub(SegmentAvailabilityInterface::class), $storage, $encoder, new NullLogger(), $this->spawner);
        $this->worker = new TranscodeSessionSubscriber($jobs, $sessions, $storage,
            new TranscodeProcessPool($pool, new NullLogger(), new JsonEncoder()),
            $encoder, $videos, new NullLogger(), $ownership, $control, $streams, $this->timer, $admission);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testCompletionPublishesActualCodecAndGenerationAndReleasesAllocation(): void
    {
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertSame(['accepted', 'ready', 'complete'], $this->observed);
        self::assertSame(TranscodeStatus::Completed, $this->job->getStatus());
        self::assertStringEndsWith('/generation-1', $this->job->getOutputDirectory());
        $metadata = json_decode(file_get_contents($this->job->getOutputDirectory() . '/generation.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('h264', $metadata['videoCodec']);
        self::assertSame('libx264', $metadata['videoEncoder']);
        self::assertSame(1, $metadata['generation']);
        self::assertSame('streaming_stereo', $metadata['audioProfile']['name']);
        self::assertSame(0, $this->allocations);
        self::assertSame(1, $this->releases);
    }

    public function testPausedSeekReapsOldProcessBeforeStartingReplacementAndCancelEndsIt(): void
    {
        $this->spawner->running = true;
        $phase = 0;
        $this->spawner->onRunning = function () use (&$phase): void {
            if ($phase === 0) {
                $phase = 1;
                $this->current = $this->claim(2, 2, 'paused', AudioProfile::mobileMono());
            } elseif (count($this->spawner->commands) === 2 && $phase === 1) {
                $phase = 2;
                $this->current = $this->claim(2, 3, 'cancelled', AudioProfile::mobileMono());
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(2, $this->spawner->commands);
        self::assertSame(['spawn:1', 'kill:1', 'close:1', 'spawn:2'], array_slice($this->spawner->events, 0, 4));
        self::assertContains('cancelled', $this->observed);
        self::assertNotContains('failed', $this->observed);
        self::assertNotContains('complete', $this->observed);
        self::assertSame(0, $this->allocations);
        $second = $this->spawner->commands[1];
        self::assertStringContainsString('/generation-2/', $second[array_key_last($second)]);
        self::assertSame('32000', $second[array_search('-b:a', $second, true) + 1]);
    }

    public function testLostOwnershipDuringPollingCannotPublishOrFailOrSave(): void
    {
        $this->spawner->running = true;
        $this->spawner->onRunning = function (): void { $this->current = null; };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertSame(['accepted'], $this->observed);
        self::assertSame(0, $this->savesAfterLoss);
        self::assertSame(0, $this->releases);
        self::assertSame(0, $this->allocations);
        self::assertContains('kill:1', $this->spawner->events);
    }

    public function testProbeOwnershipLossCannotLaunchEncoder(): void
    {
        $this->onDispatch = function (): void { $this->current = null; };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertSame([], $this->spawner->commands);
        self::assertSame(['accepted'], $this->observed);
        self::assertSame(0, $this->allocations);
    }

    public function testClosedMediaFragmentCannotPublishTruncatedInit(): void
    {
        $this->spawner->initBytes = pack('N', 12) . 'ftypisom' . pack('N', 100) . 'moov';
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertNotContains('ready', $this->observed);
        self::assertNotContains('complete', $this->observed);
        self::assertContains('failed', $this->observed);
        self::assertSame(0, $this->allocations);
    }

    public function testPauseAndResumeApplyCommittedDesiredRevisions(): void
    {
        $this->current = $this->claim(1, 1, 'paused');
        $this->spawner->running = true;
        $phase = 0;
        $this->spawner->onRunning = function () use (&$phase): void {
            ++$phase;
            if ($phase === 2) {
                $this->current = $this->claim(1, 2, 'running');
            } elseif ($phase === 3) {
                $this->current = $this->claim(1, 3, 'cancelled');
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(1, $this->spawner->commands);
        self::assertContains('signal-19:1', $this->spawner->events);
        self::assertContains('signal-18:1', $this->spawner->events);
        self::assertContains('kill:1', $this->spawner->events);
        self::assertContains('cancelled', $this->observed);
        self::assertNotContains('failed', $this->observed);
    }

    public function testShutdownDuringProbeReleasesWithoutFailureOrEncoderLaunch(): void
    {
        $stop = false;
        $this->onDispatch = static function () use (&$stop): void { $stop = true; };
        $this->worker->run($this->current, static function () use (&$stop): bool { return $stop; });
        self::assertSame([], $this->spawner->commands);
        self::assertSame(['accepted'], $this->observed);
        self::assertSame(1, $this->releases);
        self::assertSame(0, $this->allocations);
    }

    public function testCleanupFailureStillReleasesClaimAndAllocation(): void
    {
        $this->timer->throwOnClear = true;
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertSame(1, $this->releases);
        self::assertSame(0, $this->allocations);
        self::assertSame(TranscodeStatus::Completed, $this->job->getStatus());
    }

    public function testFailedRenewalCannotAcceptOrAllocate(): void
    {
        $this->renewAllowed = false;
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertSame([], $this->observed);
        self::assertSame([], $this->spawner->commands);
        self::assertSame(0, $this->allocations);
    }

    public function testControlRevisionRaceDuringProgressReconcilesWithoutLosingSegments(): void
    {
        $fences = 0;
        $this->onFence = function () use (&$fences): void {
            if (++$fences === 2) {
                $this->current = $this->claim(1, 2);
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertContains('complete', $this->observed);
        self::assertSame(1, $this->job->getCompletedSegments());
        self::assertSame(0, $this->allocations);
    }

    public function testPositionProgressReleasesBufferThrottleAfterFarSeekWithoutNewGeneration(): void
    {
        $this->current = $this->claim(1, 1, position: 180.0);
        $this->spawner->running = true;
        $polls = 0;
        $progressed = false;
        $this->spawner->onRunning = function () use (&$polls, &$progressed): void {
            ++$polls;
            $command = $this->spawner->commands[0];
            $directory = dirname($command[array_key_last($command)]);
            if ($polls === 1) {
                file_put_contents($directory . '/v0_a0_720p_30.m4s', 'current position');
            } elseif ($polls === 2) {
                file_put_contents($directory . '/v0_a0_720p_42.m4s', 'buffer high watermark');
            }
            if (!$progressed && in_array('signal-19:1', $this->spawner->events, true)) {
                $progressed = true;
                $this->current = $this->claim(1, 2, position: 252.0);
            }
            if (in_array('signal-18:1', $this->spawner->events, true) || $polls > 12) {
                $this->current = $this->claim(1, 3, 'cancelled', position: 252.0);
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(1, $this->spawner->commands);
        self::assertContains('signal-19:1', $this->spawner->events);
        self::assertContains('signal-18:1', $this->spawner->events);
        self::assertContains('cancelled', $this->observed);
        self::assertSame(0, $this->allocations);
    }

    public function testFarSeekPublishesActualGenerationTimelineOffset(): void
    {
        $this->sourceDuration = 300.0;
        $this->current = $this->claim(1, 1, position: 180.0);
        $this->worker->run($this->current, static fn(): bool => false);
        $metadata = json_decode(file_get_contents($this->job->getOutputDirectory() . '/generation.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(27, $metadata['startSegment']);
        self::assertSame(162.0, (float) $metadata['startTime']);
        $command = $this->spawner->commands[0];
        self::assertSame('162.000000', $command[array_search('-ss', $command, true) + 1]);
        self::assertSame('27', $command[array_search('-start_number', $command, true) + 1]);
    }

    private function claim(int $generation, int $revision, string $status = 'running', ?AudioProfile $profile = null, float $position = 0.0): TranscodeExecutionClaim
    {
        return new TranscodeExecutionClaim(new TranscodeRuntimeSnapshot($this->job->getId(), $this->session->getId(), 'request',
            new DesiredTranscodeState($profile ?? AudioProfile::streamingStereo(), $status, $position), $revision, 0, $generation, 'pending', null, null, true),
            new MediaWorkerIdentity('test', str_repeat('a', 32), 'media', 1), str_repeat('b', 64));
    }

    private function claimMatches(TranscodeExecutionClaim $claim): bool
    {
        return $this->current !== null && $claim->runtime->generation === $this->current->runtime->generation
            && $claim->runtime->desiredRevision === $this->current->runtime->desiredRevision;
    }
}

final class VideoExecutionSpawner implements ProcessSpawnerInterface
{
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var list<string> */
    public array $events = [];
    public bool $running = false;
    public string $initBytes = "\0\0\0\x0cftypisom\0\0\0\x08moov";
    public ?Closure $onRunning = null;

    public function spawn(array $command): array
    {
        $this->commands[] = $command;
        $pid = count($this->commands);
        $this->events[] = 'spawn:' . $pid;
        $directory = dirname($command[array_key_last($command)]);
        file_put_contents($directory . '/init.mp4', $this->initBytes);
        file_put_contents($directory . '/v0_a0_720p_0.m4s', 'segment');
        return ['resource' => $pid, 'pipes' => [], 'pid' => $pid];
    }
    public function isRunning(mixed $resource): bool { ($this->onRunning)?->__invoke(); return $this->running; }
    public function exitCode(mixed $resource): int { return 0; }
    public function signal(int $pid, int $signal): void { $this->events[] = ($signal === 9 ? 'kill:' : 'signal-' . $signal . ':') . $pid; }
    public function close(mixed $resource): void { $this->events[] = 'close:' . $resource; }
}

final class VideoExecutionRenewalTimer implements LoopLockRenewalTimerInterface
{
    public bool $throwOnClear = false;
    public function tick(int $intervalMs, Closure $callback): int { return 1; }
    public function clear(int $id): void
    {
        if ($this->throwOnClear) {
            throw new \RuntimeException('Timer cleanup failed.');
        }
    }
}
