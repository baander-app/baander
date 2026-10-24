<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\QoL\Application\Port\StreamAdmissionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\PublishedPresentationFixture;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
    /** @var array<string, mixed>|null */
    private ?array $sourceProbeOutput = null;
    private string $sourceFrameRate = '0/0';
    /** @var list<array<string, mixed>>|null */
    private ?array $sourceAudioStreams = null;
    private ?float $sourceTimestampOrigin = null;
    /** @var list<list<string>> */
    private array $probeCommands = [];
    private bool $sourceHasAudio = true;
    /** @var list<array<string, mixed>> */
    private array $subtitleStreams = [];
    /** @var array<int, list<string>> */
    private array $subtitleCommands = [];
    /** @var list<int> */
    private array $subtitleStopped = [];
    private int $subtitlePolls = 0;
    private int $subtitleDelay = 0;
    private bool $subtitleFails = false;
    private ?Closure $onSubtitlePoll = null;
    private TranscodeProcessPool $helpers;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-worker-video-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        file_put_contents($this->root . '/source', 'source');
        $video = Video::create($this->root . '/source', 'video-test');
        $this->job = TranscodeJob::create($video->getId(), QualityTier::p720(), $this->root, \App\Shared\Domain\Model\Uuid::generate());
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
        $pool = $this->createStub(NativeExecutionClientInterface::class);
        $pool->method('handshake')->willReturn(['ready' => true]);
        $pool->method('signal')->willReturnCallback(function (NativeExecutionHandle $handle): array {
            $this->subtitleStopped[] = $handle->attempt;
            return ['state' => 'exited'];
        });
        $pool->method('release')->willReturn(true);
        $pool->method('submit')->willReturnCallback(function (array $arguments): NativeExecutionHandle {
            if ($arguments[0] === '/usr/bin/ffprobe') {
                $this->probeCommands[] = $arguments;
                ($this->onDispatch)?->__invoke();
                return new NativeExecutionHandle(str_repeat('a', 64), 1);
            }
            $index = count($this->subtitleCommands) + 1;
            $this->subtitleCommands[$index] = $arguments;
            return new NativeExecutionHandle(str_repeat('c', 64), $index);
        });
        $pool->method('inspect')->willReturnCallback(function (NativeExecutionHandle $handle): array {
            if ($handle->token === str_repeat('c', 64)) {
                ++$this->subtitlePolls;
                ($this->onSubtitlePoll)?->__invoke();
                if (in_array($handle->attempt, $this->subtitleStopped, true)) {
                    return ['state' => 'exited', 'exitCode' => 1, 'stderr' => 'Stopped', 'error' => null, 'stdout' => ''];
                }
                if ($this->subtitlePolls <= $this->subtitleDelay) {
                    return ['state' => 'running'];
                }
                $arguments = $this->subtitleCommands[$handle->attempt];
                file_put_contents($arguments[array_key_last($arguments)], "WEBVTT\n\n00:01.000 --> 00:02.000\nCue\n");
                return ['state' => 'exited', 'exitCode' => $this->subtitleFails ? 1 : 0, 'stderr' => $this->subtitleFails ? 'Failed subtitle conversion' : '', 'error' => null, 'stdout' => ''];
            }
            return ['state' => 'exited', 'exitCode' => 0, 'stderr' => '', 'error' => null, 'stdout' => json_encode($this->sourceProbeOutput ?? [
                'format' => ['duration' => $this->sourceDuration + ($this->sourceTimestampOrigin ?? 0.0),
                    'format_name' => 'matroska,webm', 'start_time' => ($this->sourceTimestampOrigin ?? 0.0) + 8.0],
                'packets' => $this->sourceTimestampOrigin === null ? [] : [['stream_index' => 0, 'pts_time' => $this->sourceTimestampOrigin, 'flags' => 'K_']],
                'streams' => array_merge([['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720, 'r_frame_rate' => $this->sourceFrameRate, 'has_b_frames' => 0]],
                    $this->sourceHasAudio ? ($this->sourceAudioStreams ?? [['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'sample_rate' => '48000']]) : [], $this->subtitleStreams),
            ], JSON_THROW_ON_ERROR)];
        });
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
        $this->helpers = new TranscodeProcessPool($pool, new NullLogger(), new JsonEncoder());
        $this->worker = new TranscodeSessionSubscriber($jobs, $sessions, $storage,
            $this->helpers,
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

    public function testSelectedVideoEndpointControlsWorkerReadinessAndPublishedDuration(): void
    {
        $this->sourceProbeOutput = [
            'format' => ['format_name' => 'mov,mp4', 'start_time' => '0', 'duration' => '10'],
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720,
                    'start_time' => '0', 'duration' => '6', 'r_frame_rate' => '25/1'],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'sample_rate' => '48000', 'duration' => '10'],
            ],
        ];
        $this->current = $this->claim(1, 1, 'paused', position: 6.0);
        $this->worker->run($this->current, static fn(): bool => false);

        self::assertSame(['accepted', 'ready', 'complete'], $this->observed);
        self::assertSame(TranscodeStatus::Completed, $this->job->getStatus());
        self::assertSame(6.0, $this->job->getProbeData()['duration']);
        self::assertSame(1, $this->job->getTotalSegments());
        $metadata = json_decode(file_get_contents($this->job->getOutputDirectory().'/generation.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(6.0, (float) $metadata['duration']);
        self::assertSame(0.0, (float) $metadata['startTime']);
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

    public function testDefaultAudioReachesInitialSeekReplacementAndRecoveredLaunches(): void
    {
        $this->sourceFrameRate = '25/1';
        $this->sourceAudioStreams = [
            ['index' => 4, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 1,
                'sample_rate' => '44100', 'channel_layout' => 'mono', 'disposition' => ['default' => 0]],
            ['index' => 7, 'codec_type' => 'audio', 'codec_name' => 'pcm_s16le', 'channels' => 6,
                'sample_rate' => '48000', 'channel_layout' => '5.1(side)', 'disposition' => ['default' => 1]],
        ];
        $this->sourceDuration = 60.0;
        $this->spawner->running = true;
        $phase = 0;
        $this->spawner->onRunning = function () use (&$phase): void {
            if ($phase === 0) {
                $phase = 1;
                $this->current = $this->claim(2, 2, position: 24.0);
            } elseif (count($this->spawner->commands) === 2) {
                // The owning worker disappears after the replacement was launched.
                $this->current = null;
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(2, $this->spawner->commands);
        self::assertSame(TranscodeStatus::InProgress, $this->job->getStatus());
        $this->current = $this->claim(3, 3, position: 24.0);
        $this->spawner->onRunning = function (): void {
            $this->current = $this->claim(3, 4, 'cancelled', position: 24.0);
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(3, $this->spawner->commands);
        foreach ($this->spawner->commands as $index => $command) {
            self::assertContains('1:a:1', $command);
            self::assertNotContains('1:a:0', $command);
            $filters = $command[array_search('-af', $command, true) + 1];
            self::assertStringContainsString('pan=stereo', $filters);
            self::assertStringContainsString('0.1847*SL', $filters);
            self::assertStringContainsString('0.1847*SR', $filters);
            self::assertStringContainsString('equalizer', $filters);
            self::assertStringNotContainsString('aresample=48000', $filters);
            if ($index === 0) {
                self::assertNotContains('-ss', $command);
            } else {
                self::assertSame('6.000000', $command[array_search('-ss', $command, true) + 1]);
            }
        }
        $retained = VideoProbeResult::fromSerialized($this->job->getProbeData());
        self::assertSame(1, $retained->selectedAudioStreamOrdinal());
        self::assertSame(6, $retained->audioChannels);
        self::assertSame('pcm_s16le', $retained->audioCodec);
        self::assertSame('5.1(side)', $retained->audioChannelLayout);
        self::assertSame(48000, $retained->audioSampleRate);
        self::assertNotContains('failed', $this->observed);
        self::assertContains('cancelled', $this->observed);
        self::assertSame(0, $this->allocations);
    }

    public function testSparseProbeReachesInitialSeekReplacementAndRecoveredLaunches(): void
    {
        $this->sourceFrameRate = '1/10';
        $this->sourceDuration = 60.0;
        $this->spawner->running = true;
        $phase = 0;
        $this->spawner->onRunning = function () use (&$phase): void {
            if ($phase === 0) {
                $phase = 1;
                $this->current = $this->claim(2, 2, position: 24.0);
            } elseif (count($this->spawner->commands) === 2) {
                // The owning worker disappears after the replacement was launched.
                $this->current = null;
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(2, $this->spawner->commands);
        self::assertSame(TranscodeStatus::InProgress, $this->job->getStatus());
        $this->current = $this->claim(3, 3, position: 24.0);
        $this->spawner->onRunning = function (): void {
            $this->current = $this->claim(3, 4, 'cancelled', position: 24.0);
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(3, $this->spawner->commands);
        foreach ($this->spawner->commands as $index => $command) {
            self::assertStringEndsWith('fps=1:start_time=0:round=up', $command[array_search('-vf', $command, true) + 1]);
            self::assertSame('0', $command[array_search('-bf:v', $command, true) + 1]);
            if ($index === 0) {
                self::assertNotContains('-ss', $command);
                self::assertNotContains('-noaccurate_seek', $command);
            } else {
                self::assertSame('6.000000', $command[array_search('-ss', $command, true) + 1]);
                self::assertContains('-noaccurate_seek', $command);
                self::assertStringStartsWith('atrim=start=0', $command[array_search('-af', $command, true) + 1]);
            }
        }
        self::assertNotContains('failed', $this->observed);
        self::assertContains('cancelled', $this->observed);
        self::assertSame(0, $this->allocations);
    }

    public function testVerifiedOriginReachesMediaAndSubtitlesAcrossSeekAndRecovery(): void
    {
        $this->sourceFrameRate = '1/10';
        $this->sourceTimestampOrigin = 4.0;
        $this->sourceDuration = 60.0;
        $this->subtitleStreams = [['index' => 2, 'codec_type' => 'subtitle', 'codec_name' => 'subrip']];
        $this->spawner->running = true;
        $phase = 0;
        $this->spawner->onRunning = function () use (&$phase): void {
            if ($phase === 0 && count($this->subtitleCommands) >= 1) {
                $phase = 1;
                $this->current = $this->claim(2, 2, position: 24.0);
            } elseif ($phase === 1 && count($this->subtitleCommands) >= 2) {
                $this->current = null;
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(2, $this->spawner->commands);
        $this->current = $this->claim(3, 3, position: 24.0);
        $this->spawner->onRunning = function (): void {
            if (count($this->subtitleCommands) >= 3) {
                $this->current = $this->claim(3, 4, 'cancelled', position: 24.0);
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertCount(3, $this->spawner->commands);
        self::assertCount(3, $this->subtitleCommands);
        self::assertCount(2, $this->probeCommands);
        foreach ($this->probeCommands as $command) {
            self::assertSame(\App\Transcode\Infrastructure\FFmpeg\FFprobeArguments::video($this->root . '/source'), $command);
        }
        foreach ($this->spawner->commands as $index => $command) {
            $sourceStart = $index === 0 ? 0.0 : 6.0;
            self::assertContains('-copyts', $command);
            self::assertSame('1', $command[array_search('-seek_timestamp', $command, true) + 1]);
            self::assertSame(sprintf('%.6f', -(4 + $sourceStart)), $command[array_search('-itsoffset', $command, true) + 1]);
            if ($index > 0) {
                $inputs = array_keys($command, '-i', true);
                $videoInput = array_slice($command, 0, $inputs[0] + 2);
                $audioInput = array_slice($command, $inputs[0] + 2, $inputs[1] - $inputs[0]);
                self::assertNotContains('-ss', $videoInput);
                self::assertNotContains('-ss', $audioInput);
                self::assertNotContains('-noaccurate_seek', $audioInput);
                self::assertNotContains('-noaccurate_seek', $command);
                self::assertStringStartsWith('atrim=start=0', $command[array_search('-af', $command, true) + 1]);
            }
            $metadata = json_decode(file_get_contents(dirname($command[array_key_last($command)]) . '/generation.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($sourceStart, (float) $metadata['startTime']);
            self::assertSame(60.0, (float) $metadata['duration']);
        }
        foreach ($this->subtitleCommands as $command) {
            self::assertContains('-copyts', $command);
            self::assertSame('-4.000000', $command[array_search('-itsoffset', $command, true) + 1]);
            self::assertNotContains('-ss', $command);
        }
        self::assertSame(4.0, $this->job->getProbeData()['sourceTimestampOrigin']);
        self::assertSame(60.0, $this->job->getProbeData()['duration']);
        self::assertNotContains('failed', $this->observed);
        self::assertSame(0, $this->allocations);
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

    public function testShutdownDuringLaunchRetiresCapturedAttemptBeforePolling(): void
    {
        $stopped = false;
        $polled = 0;
        $this->spawner->onSpawn = static function () use (&$stopped): void { $stopped = true; };
        $this->spawner->onRunning = static function () use (&$polled): void { ++$polled; };
        $this->worker->run($this->current, static function () use (&$stopped): bool { return $stopped; });
        self::assertSame(0, $polled);
        self::assertSame(['accepted'], $this->observed);
        self::assertContains('kill:1', $this->spawner->events);
        self::assertContains('close:1', $this->spawner->events);
        self::assertSame(0, $this->allocations);
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
        $this->sourceDuration = 180.0;
        $this->current = $this->claim(1, 1, 'paused');
        $this->spawner->initialCount = 4;
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
        $this->sourceDuration = 300.0;
        $this->current = $this->claim(1, 1, position: 180.0);
        $this->spawner->running = true;
        $polls = 0;
        $progressed = false;
        $this->spawner->onRunning = function () use (&$polls, &$progressed): void {
            ++$polls;
            $command = $this->spawner->commands[0];
            $directory = dirname($command[array_key_last($command)]);
            if ($polls === 1) {
                $this->spawner->publish(4);
            } elseif ($polls === 2) {
                $this->spawner->publish(16);
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
        $this->spawner->onRunning = function (): void {
            $command = $this->spawner->commands[0];
            $this->spawner->publish(4);
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertContains('ready', $this->observed);
        $metadata = json_decode(file_get_contents($this->job->getOutputDirectory() . '/generation.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $metadata['schemaVersion']);
        self::assertSame(162.0, (float) $metadata['startTime']);
        $command = $this->spawner->commands[0];
        self::assertSame('162.000000', $command[array_search('-ss', $command, true) + 1]);
        self::assertNotContains('-start_number', $command);
    }

    public function testPausedSeekContinuesUntilTheRequestedFragmentIsClosed(): void
    {
        $this->assertPausedCursorPreroll(30.0, 180.0, 3, 2);
    }

    public function testPausedSeekAtEndOfFileWaitsForTheLastFragment(): void
    {
        $this->assertPausedCursorPreroll(30.0, 30.0, 3, 1);
    }

    public function testResumeDuringPrerollCannotPublishBeforeTheRequestedFragmentIsClosed(): void
    {
        $this->sourceDuration = 180.0;
        $this->current = $this->claim(1, 1, 'paused', position: 30.0);
        $this->spawner->running = true;
        $polls = 0;
        $readyBeforeTarget = false;
        $this->spawner->onRunning = function () use (&$polls, &$readyBeforeTarget): void {
            ++$polls;
            $command = $this->spawner->commands[0];
            $path = dirname($command[array_key_last($command)]) . '/track-0-seg-00004.m4s';
            if ($polls === 1) {
                file_put_contents($path . '.tmp', PublishedPresentationFixture::fragment());
            } elseif ($polls === 2) {
                $this->current = $this->claim(1, 2, 'running', position: 30.0);
            } elseif ($polls === 4) {
                $readyBeforeTarget = in_array('ready', $this->observed, true);
                $this->spawner->publish(4);
            } elseif ($polls >= 5) {
                $this->current = $this->claim(1, 3, 'cancelled', position: 30.0);
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertFalse($readyBeforeTarget, 'Resuming advertised a rendition before its requested fragment was published.');
        self::assertSame(['accepted', 'ready', 'cancelled'], $this->observed);
        self::assertNotContains('signal-19:1', $this->spawner->events);
        self::assertCount(1, $this->spawner->commands);
        self::assertSame(0, $this->allocations);
    }

    public function testRunningGenerationPausesOnlyOnceItsRequestedCursorIsPublished(): void
    {
        $this->sourceDuration = 180.0;
        $this->current = $this->claim(1, 1, position: 12.0);
        $this->spawner->running = true;
        $polls = 0;
        $pausedBeforeTarget = false;
        $this->spawner->onRunning = function () use (&$polls, &$pausedBeforeTarget): void {
            ++$polls;
            $command = $this->spawner->commands[0];
            $directory = dirname($command[array_key_last($command)]);
            if ($polls === 1) {
                $this->spawner->publish(3);
            } elseif ($polls === 2) {
                $this->current = $this->claim(1, 2, 'paused', position: 30.0);
            } elseif ($polls === 4) {
                $pausedBeforeTarget = in_array('signal-19:1', $this->spawner->events, true);
                $this->spawner->publish(9);
            } elseif ($polls >= 6) {
                $this->current = $this->claim(1, 3, 'cancelled', position: 30.0);
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertFalse($pausedBeforeTarget);
        self::assertContains('signal-19:1', $this->spawner->events);
        self::assertSame(['accepted', 'ready', 'ready', 'cancelled'], $this->observed);
        self::assertCount(1, $this->spawner->commands);
        self::assertSame(0, $this->allocations);
    }

    public function testSubtitleTracksQueueBehindOneHelperUntilAllTracksPublish(): void
    {
        foreach ([2, 3, 4] as $index) {
            $this->subtitleStreams[] = ['index' => $index, 'codec_type' => 'subtitle', 'codec_name' => 'subrip'];
        }
        $this->subtitleDelay = 3;
        $maximumActive = 0;
        $this->onSubtitlePoll = function () use (&$maximumActive): void {
            $active = 0;
            foreach ($this->subtitleCommands as $arguments) {
                $temporary = $arguments[array_key_last($arguments)];
                if (!is_file(substr($temporary, 0, -5))) {
                    ++$active;
                }
            }
            $maximumActive = max($maximumActive, $active);
            self::assertNotContains('ready', $this->observed, 'Initial readiness must settle the complete subtitle queue.');
        };
        $this->worker->run($this->current, static fn (): bool => false);
        self::assertSame(1, $maximumActive, 'Three video jobs must fit their encoders and subtitle helpers within the shared native budget.');
        self::assertCount(3, $this->subtitleCommands);
        foreach (['s2', 's3', 's4'] as $key) {
            self::assertFileExists($this->job->getOutputDirectory().'/subtitles/'.$key.'/full.vtt');
        }
        self::assertSame(['accepted', 'ready', 'complete'], $this->observed);
    }

    public function testSlowFailedSubtitlesKeepPauseAndCancelControlsResponsive(): void
    {
        $this->subtitleStreams = [['index' => 2, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => 'eng']]];
        $this->subtitleDelay = 4;
        $this->subtitleFails = true;
        $this->sourceDuration = 180.0;
        $this->spawner->initialCount = 4;
        $this->spawner->running = true;
        $readyBeforeFinished = false;
        $this->onSubtitlePoll = function () use (&$readyBeforeFinished): void {
            $readyBeforeFinished = $readyBeforeFinished || in_array('ready', $this->observed, true);
            if ($this->subtitlePolls === 1) {
                $this->current = $this->claim(1, 2, 'paused');
            }
        };
        $this->spawner->onRunning = function (): void {
            if (in_array('ready', $this->observed, true)) {
                $this->current = $this->claim(1, 3, 'cancelled');
            }
        };
        $this->worker->run($this->current, static fn (): bool => false);
        self::assertFalse($readyBeforeFinished);
        self::assertGreaterThan(4, $this->subtitlePolls);
        self::assertContains('signal-19:1', $this->spawner->events);
        self::assertSame(['accepted', 'ready', 'cancelled'], $this->observed);
        self::assertFileDoesNotExist($this->job->getOutputDirectory().'/subtitles/s2/full.vtt');
        self::assertFileDoesNotExist($this->job->getOutputDirectory().'/subtitles/s2/full.vtt.part');
        self::assertSame(0, $this->allocations);
    }

    public function testSeekRetiresSlowSubtitleHelpersBeforeReplacementPublishes(): void
    {
        $this->subtitleStreams = [['index' => 2, 'codec_type' => 'subtitle', 'codec_name' => 'subrip']];
        $this->subtitleDelay = 1;
        $this->onSubtitlePoll = function (): void {
            if ($this->subtitlePolls === 1) {
                $this->current = $this->claim(2, 2);
            }
        };
        $this->worker->run($this->current, static fn (): bool => false);
        self::assertSame([1], $this->subtitleStopped);
        self::assertCount(2, $this->subtitleCommands);
        $oldPath = $this->subtitleCommands[1][array_key_last($this->subtitleCommands[1])];
        self::assertStringContainsString('/generation-1/', $oldPath);
        self::assertFileDoesNotExist($oldPath);
        self::assertFileDoesNotExist(substr($oldPath, 0, -5));
        self::assertFileExists($this->job->getOutputDirectory().'/subtitles/s2/full.vtt');
        self::assertStringEndsWith('/generation-2', $this->job->getOutputDirectory());
        self::assertSame(['accepted', 'ready', 'complete'], $this->observed);
    }

    public function testExpiredSubtitleBatchRetiresHelpersAndClearsQueuedTracks(): void
    {
        $track = \App\Transcode\Domain\ValueObject\SubtitleTrackInfo::fromProbeStream(['index' => 2, 'codec_name' => 'subrip']);
        $path = $this->root.'/subtitles/s2/full.vtt';
        $key = $this->helpers->extractSubtitles($this->job, $this->root.'/source', 2, $path, 1);
        file_put_contents($path.'.part', 'unfinished');
        $queue = [$track];
        $active = [$key => $track];
        $storage = new TranscodeFileStorage(new SegmentFileResolver($this->root));
        $arguments = [$this->current, $this->job, $this->root.'/source', $storage, 0.0, null, &$queue, &$active, hrtime(true) - 1];
        (new \ReflectionMethod(TranscodeSessionSubscriber::class, 'pollSubtitles'))->invokeArgs($this->worker, $arguments);
        self::assertSame([], $queue);
        self::assertSame([], $active);
        self::assertSame([1], $this->subtitleStopped);
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path.'.part');
    }

    public function testVideoOnlyGenerationDoesNotAdvertiseOrMapAudio(): void
    {
        $this->sourceHasAudio = false;
        $this->worker->run($this->current, static fn (): bool => false);
        $metadata = json_decode(file_get_contents($this->job->getOutputDirectory().'/generation.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($metadata['audioCodec']);
        self::assertNotContains('-c:a', $this->spawner->commands[0]);
        self::assertNotContains('1:a:0', $this->spawner->commands[0]);
        self::assertCount(1, array_keys($this->spawner->commands[0], '-i', true));
        self::assertSame(['accepted', 'ready', 'complete'], $this->observed);
    }

    public function testRequiredAudioAbsenceCannotAcknowledgeReadiness(): void
    {
        $this->spawner->publishAudio = false;
        $this->worker->run($this->current, static fn (): bool => false);
        self::assertSame(['accepted', 'failed'], $this->observed);
        self::assertSame(TranscodeStatus::Failed, $this->job->getStatus());
    }

    #[DataProvider('publishedAudioTailLengths')]
    public function testSilentTailReadinessRequiresPublishedAudioAtTheRequestedCursor(bool $fullAudioTail): void
    {
        $this->sourceDuration = 14.0;
        $this->sourceFrameRate = '25/1';
        $this->current = $this->claim(1, 1, 'paused', position: 12.0);
        $this->spawner->onSpawn = function () use ($fullAudioTail): void {
            $command = $this->spawner->commands[0];
            $path = $command[array_key_last($command)];
            $directory = dirname($path);
            PublishedPresentationFixture::write($directory, 3, audio: true);
            $xml = new \DOMDocument();
            $xml->load($path);
            $xpath = new \DOMXPath($xml);
            $xpath->registerNamespace('m', 'urn:mpeg:dash:schema:mpd:2011');
            foreach (['video', 'audio'] as $kind) {
                $timelines = $xpath->query('//m:AdaptationSet[@contentType="' . $kind . '"]//m:SegmentTimeline');
                self::assertNotFalse($timelines);
                $timeline = $timelines->item(0);
                self::assertInstanceOf(\DOMElement::class, $timeline);
                while ($timeline->firstChild !== null) {
                    $timeline->removeChild($timeline->firstChild);
                }
                $durations = $kind === 'audio' && !$fullAudioTail ? [4000] : [6000, 6000, 2000];
                foreach ($durations as $duration) {
                    $point = $xml->createElementNS('urn:mpeg:dash:schema:mpd:2011', 'S');
                    $point->setAttribute('d', (string) $duration);
                    $timeline->appendChild($point);
                }
            }
            $xml->save($path);
        };
        $this->worker->run($this->current, static fn (): bool => false);
        if ($fullAudioTail) {
            self::assertSame(['accepted', 'ready', 'complete'], $this->observed);
            self::assertSame(TranscodeStatus::Completed, $this->job->getStatus());
            self::assertSame(3, $this->job->getCompletedSegments());
            self::assertSame(12.0, $this->current->runtime->desired->position);
        } else {
            self::assertSame(['accepted', 'failed'], $this->observed);
            self::assertSame(TranscodeStatus::Failed, $this->job->getStatus());
        }
        self::assertSame(0, $this->allocations);
    }

    /** @return iterable<string, array{bool}> */
    public static function publishedAudioTailLengths(): iterable
    {
        yield 'published silence reaches cursor' => [true];
        yield 'audio ending before cursor stays unready' => [false];
    }

    public function testCompletionSettlesTheActualGenerationLocalCount(): void
    {
        $this->sourceDuration = 6.01;
        $this->worker->run($this->current, static fn (): bool => false);
        self::assertSame(['accepted', 'ready', 'complete'], $this->observed);
        self::assertSame(1, $this->job->getTotalSegments());
        self::assertSame(1, $this->job->getCompletedSegments());
        self::assertSame(100.0, $this->job->getProgress());
    }

    public function testExactPausedEofAcceptsAShorterCompletedAudioTail(): void
    {
        $this->current = $this->claim(1, 1, 'paused', position: 6.0);
        $this->spawner->onSpawn = function (): void {
            $command = $this->spawner->commands[0];
            $path = $command[array_key_last($command)];
            $xml = new \DOMDocument();
            $xml->load($path);
            $xpath = new \DOMXPath($xml);
            $xpath->registerNamespace('m', 'urn:mpeg:dash:schema:mpd:2011');
            $points = $xpath->query('//m:AdaptationSet[@contentType="audio"]//m:S');
            self::assertNotFalse($points);
            $point = $points->item(0);
            self::assertInstanceOf(\DOMElement::class, $point);
            $point->setAttribute('d', '5989');
            $xml->save($path);
        };
        $this->worker->run($this->current, static fn (): bool => false);
        self::assertSame(['accepted', 'ready', 'complete'], $this->observed);
        self::assertSame(6.0, $this->current->runtime->desired->position);
    }

    public function testDynamicClockIsCapturedBeforeReadiness(): void
    {
        $this->sourceDuration = 180.0;
        $this->spawner->running = true;
        $this->spawner->onRunning = function (): void {
            if (in_array('ready', $this->observed, true)) {
                $this->current = $this->claim(1, 2, 'cancelled');
            }
        };
        $this->worker->run($this->current, static fn (): bool => false);
        $metadata = json_decode(file_get_contents($this->job->getOutputDirectory() . '/generation.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2026-10-22T00:00:00Z', $metadata['nativeAvailabilityStartTime']);
        self::assertSame(['accepted', 'ready', 'cancelled'], $this->observed);
    }

    private function assertPausedCursorPreroll(float $position, float $duration, int $target, int $start): void
    {
        $this->sourceDuration = $duration;
        $this->current = $this->claim(1, 1, 'paused', position: $position);
        $this->spawner->running = true;
        $polls = 0;
        $pausedBeforeTarget = false;
        $readyBeforeTarget = false;
        $pausedWithTarget = false;
        $this->spawner->onRunning = function () use (&$polls, &$pausedBeforeTarget, &$readyBeforeTarget, &$pausedWithTarget, $target, $position): void {
            ++$polls;
            $command = $this->spawner->commands[0];
            $directory = dirname($command[array_key_last($command)]);
            $path = $directory . sprintf('/track-0-seg-%05d.m4s', $target + 1);
            if ($polls === 1) {
                file_put_contents($path . '.tmp', PublishedPresentationFixture::fragment());
            } elseif ($polls === 3) {
                $pausedBeforeTarget = in_array('signal-19:1', $this->spawner->events, true);
                $readyBeforeTarget = in_array('ready', $this->observed, true);
                // Closing just the cursor fragment still does not provide the player's holdback.
                $this->spawner->publish($target + 1, $this->sourceDuration <= $position);
            } elseif ($polls === 4 && $this->sourceDuration > $position) {
                $this->spawner->publish($target + 5);
            } elseif ($polls >= 6) {
                $pausedWithTarget = in_array('signal-19:1', $this->spawner->events, true);
                $this->current = $this->claim(1, 2, 'cancelled');
            }
        };
        $this->worker->run($this->current, static fn(): bool => false);
        self::assertFalse($pausedBeforeTarget, 'The encoder paused with only preroll and an unfinished target fragment.');
        self::assertFalse($readyBeforeTarget, 'A paused cursor was acknowledged before its fragment was published.');
        self::assertTrue($pausedWithTarget);
        self::assertSame(['accepted', 'ready', 'cancelled'], $this->observed);
        self::assertSame(0, $this->allocations);
        self::assertSame(1, $this->releases);
        $metadata = json_decode(file_get_contents($this->job->getOutputDirectory() . '/generation.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($start * 6.0, (float) $metadata['startTime']);
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
    public int $initialCount = 1;
    public ?bool $publishAudio = null;
    public string $initBytes = "\0\0\0\x0cftypisom\0\0\0\x08moov";
    public ?Closure $onRunning = null;
    public ?Closure $onSpawn = null;

    public function spawn(array $command): mixed
    {
        $this->commands[] = $command;
        $pid = count($this->commands);
        $this->events[] = 'spawn:' . $pid;
        $directory = dirname($command[array_key_last($command)]);
        $this->publish($this->initialCount);
        ($this->onSpawn)?->__invoke();
        return $pid;
    }
    public function publish(int $count, ?bool $complete = null): void
    {
        $command = $this->commands[array_key_last($this->commands)];
        $directory = dirname($command[array_key_last($command)]);
        PublishedPresentationFixture::write($directory, $count, $this->publishAudio ?? array_any($command, static fn (string $argument): bool => preg_match('/^1:a:[0-9]+$/D', $argument) === 1), $complete ?? !$this->running);
        file_put_contents($directory . '/init-0.mp4', $this->initBytes);
    }
    public function status(mixed $handle): array { ($this->onRunning)?->__invoke(); return ['state' => $this->running ? 'running' : 'exited', 'exitCode' => 0, 'stdout' => '', 'stderr' => '', 'error' => null]; }
    public function signal(mixed $pid, string $signal): void { $this->events[] = ($signal === 'stop' ? 'kill:' : 'signal-' . ($signal === 'pause' ? '19' : '18') . ':') . $pid; }
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
