<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\Event\TranscodeJobFailed;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Swoole\JobStatePersister;
use App\Transcode\Infrastructure\Swoole\LoopLockRenewalTimerInterface;
use App\Transcode\Infrastructure\Swoole\ProcessSpawnerInterface;
use App\Transcode\Infrastructure\Swoole\SeekSignalBroker;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use App\Transcode\Infrastructure\Swoole\TranscodeStreamManager;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class TranscodeSessionSubscriberOwnershipTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-ownership-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory . '/output');
        mkdir($this->directory . '/results');
        file_put_contents($this->directory . '/source', 'source fixture');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->directory);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function lossBoundaries(): iterable
    {
        foreach (['initial renewal', 'cached fragment', 'poll', 'seek wait', 'probe', 'job save', 'pool wait', 'error after loss'] as $boundary) {
            yield $boundary . '/refused' => [$boundary, false];
            yield $boundary . '/exception' => [$boundary, true];
        }
    }

    #[DataProvider('lossBoundaries')]
    public function testObservedLossStopsLocalWorkWithoutFurtherMutations(string $boundary, bool $throw): void
    {
        $this->runInCoroutine(function () use ($boundary, $throw): void {
            $this->runFixture($boundary, $throw);
        });
    }

    public function testExpiredDeadlineStopsResumedWorkBeforeTheRenewalTimerRuns(): void
    {
        $this->runInCoroutine(function (): void {
            $this->runFixture('expired poll', false);
        });
    }

    public function testNormalCompletionStillPersistsAndEmitsCompleted(): void
    {
        $this->runInCoroutine(function (): void {
            $this->runFixture('complete', false);
        });
    }

    public function testOrdinaryEncoderFailureStillFailsJob(): void
    {
        $this->runInCoroutine(function (): void {
            $this->runFixture('ordinary error', false);
        });
    }

    public function testTimerCleanupFailureStillStopsProcessAndClosesBrokerWithoutFurtherWrites(): void
    {
        $this->runInCoroutine(function (): void {
            $this->runFixture('ordinary error', false, true);
        });
    }

    private function runInCoroutine(Closure $callback): void
    {
        $failure = null;
        \Swoole\Coroutine\run(static function () use ($callback, &$failure): void {
            try {
                $callback();
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function runFixture(string $boundary, bool $throw, bool $cleanupFailure = false): void
    {
        $timer = new CapturedOwnershipRenewalTimer();
        $spawner = new OwnershipProcessSpawner();
        $broker = new SeekSignalBroker();
        $json = new JsonEncoder();
        $logger = new NullLogger();
        $video = Video::create($this->directory . '/source', 'ownership-fixture');
        $tier = QualityTier::p720();
        $job = TranscodeJob::create($video->getId(), $tier, $this->directory . '/output');
        $session = TranscodeSession::create(Uuid::generate(), $job->getId(), $video->getId(), AudioProfile::streamingStereo());
        $probe = VideoProbeResult::fromProbeOutput([
            'format' => ['duration' => 4],
            'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720, 'r_frame_rate' => '30/1']],
        ]);
        $job->setMeasuredLoudness(['input_i' => -24]);
        $fresh = in_array($boundary, ['probe', 'job save', 'pool wait'], true);
        if (!$fresh) {
            $job->updateProbeData($probe->jsonSerialize());
            $job->setTotalSegments(1);
            $job->markInProgress();
            $session->markPreparing();
            $session->markActive();
        }
        $initialLoss = $boundary === 'initial renewal';
        $jobs = $this->createMock(TranscodeJobPortInterface::class);
        $jobs->expects($initialLoss ? self::never() : self::once())->method('findByUuid')->willReturn($job);
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects($initialLoss ? self::never() : self::once())->method('findByUuid')->willReturn($session);
        $videos = $this->createMock(VideoRepositoryInterface::class);
        $videos->expects($initialLoss ? self::never() : self::once())->method('findByUuid')->willReturn($video);
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn($this->directory . '/output');
        $storage->method('resolveInitSegmentPath')->willReturn($this->directory . '/output/init.mp4');
        $storage->method('resolveSegmentPath')->willReturn($this->directory . '/output/v0_a0_720p_000000.m4s');
        $actions = [];
        $lost = false;
        $jobAtLoss = null;
        $sessionAtLoss = null;
        $stateAtLoss = null;
        $stateDir = $this->directory . '/state';
        $stateFile = $stateDir . '/' . $job->getPublicId()->toString() . '.json';
        $persister = new JobStatePersister($storage, $logger, $stateDir, $json);
        file_put_contents($stateFile, 'preexisting state');
        $runtime = new class {
            public TranscodeSessionSubscriber $subscriber;
        };
        $lose = function () use (&$lost, &$jobAtLoss, &$sessionAtLoss, &$stateAtLoss, $job, $session, $stateFile, $timer, $boundary, $runtime): void {
            $lost = true;
            $jobAtLoss = clone $job->getState();
            $sessionAtLoss = clone $session->getState();
            $stateAtLoss = file_exists($stateFile) ? file_get_contents($stateFile) : null;
            if ($boundary === 'expired poll') {
                // Advance only the local deadline, without firing a timer or
                // changing the lease response. The resumed guard must stop work.
                $owners = (new ReflectionProperty($runtime->subscriber, 'loopOwnership'))->getValue($runtime->subscriber);
                (new ReflectionProperty($owners[$job->getId()->toString()], 'deadline'))
                    ->setValue($owners[$job->getId()->toString()], hrtime(true));
                return;
            }
            $timer->fire();
        };
        if ($boundary === 'cached fragment') {
            file_put_contents($this->directory . '/output/v0_a0_720p_000000.m4s', 'cached fragment');
        }
        $storage->method('exists')->willReturnCallback(static function () use ($boundary, $lose): bool {
            if ($boundary === 'cached fragment') {
                $lose();

                return true;
            }

            return false;
        });
        if ($initialLoss) {
            $lost = true;
            $jobAtLoss = clone $job->getState();
            $sessionAtLoss = clone $session->getState();
            $stateAtLoss = file_get_contents($stateFile);
        }
        $actionsAtCleanupFailure = null;
        if ($cleanupFailure) {
            $timer->onClear = static function () use (&$actionsAtCleanupFailure, &$actions): void {
                $actionsAtCleanupFailure = $actions;
                throw new RuntimeException('Injected timer cleanup failure.');
            };
        }
        $lock = $this->createMock(TranscodeLoopLeaseInterface::class);
        $lock->method('getJobId')->willReturn($job->getId());
        $renewals = 0;
        $lock->method('renew')->willReturnCallback(static function () use (&$renewals, &$lost, $throw): bool {
            ++$renewals;
            if (!$lost) {
                return true;
            }
            if ($throw) {
                throw new RuntimeException('Redis unavailable during renewal.');
            }

            return false;
        });
        $lock->expects(in_array($boundary, ['complete', 'ordinary error'], true) ? self::once() : self::never())
            ->method('release');
        $jobs->method('save')->willReturnCallback(function () use (&$actions, &$lost, $boundary, $lose): void {
            $actions[] = ['job save', $lost];
            if ($boundary === 'job save' && !$lost) {
                $lose();
            }
        });
        $sessions->method('save')->willReturnCallback(static function () use (&$actions, &$lost): void {
            $actions[] = ['session save', $lost];
        });
        $availability = $this->createStub(SegmentAvailabilityInterface::class);
        foreach (['markReady', 'clearJob'] as $method) {
            $availability->method($method)->willReturnCallback(static function () use (&$actions, &$lost, $method): void {
                $actions[] = [$method, $lost];
            });
        }
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event) use (&$actions, &$lost): object {
            $actions[] = [$event::class, $lost];

            return $event;
        });
        $ffmpeg = $this->createStub(FFmpegPortInterface::class);
        $encoder = new SegmentEncoder($ffmpeg, $storage, EncoderProfile::software('libx264'));
        $manager = new TranscodeStreamManager($availability, $storage, $encoder, $logger, $spawner);
        $pool = new CpuProcessPool([], 1, $logger, resultDir: $this->directory . '/results');
        $transcodePool = new TranscodeProcessPool($pool, $logger, $json, EncoderProfile::software('libx264'));
        $subscriber = new TranscodeSessionSubscriber(
            $jobs, $sessions, $storage, $transcodePool, $ffmpeg, $encoder, $videos, $persister, $broker,
            $events, $logger, $json, streamManager: $manager, availability: $availability,
            renewalTimer: $timer,
        );
        $runtime->subscriber = $subscriber;
        $ffmpeg->method('probeVideo')->willReturnCallback(function () use ($boundary, $lose, $probe, $subscriber, $job): VideoProbeResult {
            if ($boundary === 'probe') {
                $lose();
            } elseif ($boundary === 'pool wait') {
                $resultPath = $this->directory . '/results/' . sha1('ownership-result') . '.json';
                \Swoole\Timer::after(1, static function () use ($lose, $resultPath): void {
                    $lose();
                    file_put_contents($resultPath, json_encode(['status' => 'success', 'data' => '{}'], JSON_THROW_ON_ERROR));
                });
                $ownership = (new ReflectionProperty($subscriber, 'loopOwnership'))->getValue($subscriber);
                (new ReflectionMethod($subscriber, 'waitForResult'))->invoke($subscriber, $job->getId(), 'ownership-result', 1, 0.01, $ownership[$job->getId()->toString()]);
            }

            return $probe;
        });
        $spawner->onSpawn = function () use ($boundary): void {
            if (in_array($boundary, ['complete', 'ordinary error'], true)) {
                file_put_contents($this->directory . '/output/v0_a0_720p_000000.m4s', 'final fragment');
            }
        };
        $spawner->onPoll = function () use ($boundary, $lose, $broker, $job, $spawner): void {
            if ($boundary === 'seek wait') {
                \Swoole\Timer::after(1, static function () use ($lose, $broker, $job): void {
                    $lose();
                    $broker->signal($job->getId(), 20, 'seek');
                });
            } elseif (in_array($boundary, ['poll', 'expired poll', 'error after loss'], true)) {
                $lose();
                // A producer may finish a fragment while shutdown is observed.
                // The retired poll must not publish this late file.
                file_put_contents($this->directory . '/output/v0_a0_720p_000000.m4s', 'late fragment');
                if ($boundary === 'error after loss') {
                    throw new RuntimeException('Encoder I/O threw after ownership was lost.');
                }
            } elseif (in_array($boundary, ['complete', 'ordinary error'], true)) {
                $spawner->running = false;
                $spawner->exitStatus = $boundary === 'complete' ? 0 : 7;
            }
        };
        (new ReflectionMethod($subscriber, 'runEncodingLoop'))->invoke($subscriber, $session->getId(), $lock);

        if ($boundary === 'complete') {
            self::assertSame(TranscodeStatus::Completed, $job->getStatus());
            self::assertContains([TranscodeJobCompleted::class, false], $actions);
        } elseif ($boundary === 'ordinary error') {
            self::assertSame(TranscodeStatus::Failed, $job->getStatus());
            self::assertContains([TranscodeJobFailed::class, false], $actions);
        } else {
            self::assertTrue($lost, 'The test must observe renewal loss at its requested boundary.');
            self::assertGreaterThanOrEqual(1, $renewals);
            self::assertEquals($jobAtLoss, $job->getState());
            self::assertEquals($sessionAtLoss, $session->getState());
            self::assertSame($stateAtLoss, file_exists($stateFile) ? file_get_contents($stateFile) : null);
            self::assertSame([], array_values(array_filter($actions, static fn(array $action): bool => $action[1])));
            if ($boundary === 'pool wait') {
                self::assertFileExists($this->directory . '/results/' . sha1('ownership-result') . '.json');
            }
            if (in_array($boundary, ['poll', 'expired poll', 'seek wait', 'error after loss'], true)) {
                self::assertSame(1, $spawner->spawnCount);
                self::assertContains(9, $spawner->signals);
            }
        }
        self::assertFalse($manager->isStreaming($job->getId()));
        self::assertSame($spawner->spawnCount, $spawner->closeCount);
        self::assertSame([], $timer->callbacks);
        self::assertSame($initialLoss ? [] : [1], $timer->cleared);
        self::assertNull($broker->waitForSignal($job->getId(), 0));
        self::assertSame([], (new ReflectionProperty($broker, 'channels'))->getValue($broker));
        self::assertSame([], (new ReflectionProperty($subscriber, 'loopOwnership'))->getValue($subscriber));
        self::assertSame([], (new ReflectionProperty($subscriber, 'lockRenewTimers'))->getValue($subscriber));
        if ($cleanupFailure) {
            self::assertNotNull($actionsAtCleanupFailure);
            self::assertSame($actionsAtCleanupFailure, $actions);
            self::assertSame(1, $spawner->closeCount);
        }
        if ($initialLoss) {
            self::assertSame(1, $renewals);
            self::assertSame(0, $spawner->spawnCount);
            self::assertNull($timer->lastCallback);

            return;
        }
        if ($boundary === 'expired poll') {
            self::assertSame(1, $renewals, 'Expiry must stop work without another Redis call.');
        }
        $renewalsAfterCleanup = $renewals;
        $actionsAfterCleanup = $actions;
        self::assertNotNull($timer->lastCallback);
        ($timer->lastCallback)();
        self::assertSame($renewalsAfterCleanup, $renewals, 'A delayed callback must not renew a finished loop.');
        self::assertSame($actionsAfterCleanup, $actions);
    }
}

final class CapturedOwnershipRenewalTimer implements LoopLockRenewalTimerInterface
{
    /** @var array<int, Closure> */
    public array $callbacks = [];
    /** @var list<int> */
    public array $cleared = [];
    public ?Closure $lastCallback = null;
    public ?Closure $onClear = null;

    public function tick(int $intervalMs, Closure $callback): int
    {
        TestCase::assertSame(20000, $intervalMs);
        $this->lastCallback = $callback;
        $this->callbacks[1] = $callback;

        return 1;
    }

    public function clear(int $id): void
    {
        $this->cleared[] = $id;
        unset($this->callbacks[$id]);
        if ($this->onClear !== null) {
            ($this->onClear)();
        }
    }

    public function fire(): void
    {
        TestCase::assertArrayHasKey(1, $this->callbacks);
        ($this->callbacks[1])();
    }
}

final class OwnershipProcessSpawner implements ProcessSpawnerInterface
{
    public ?Closure $onSpawn = null;
    public ?Closure $onPoll = null;
    public bool $running = true;
    public int $exitStatus = 0;
    public int $spawnCount = 0;
    public int $closeCount = 0;
    public int $pollCount = 0;
    /** @var list<int> */
    public array $signals = [];

    public function spawn(array $command): array
    {
        ++$this->spawnCount;
        if ($this->onSpawn !== null) {
            ($this->onSpawn)();
        }

        return ['resource' => new \stdClass(), 'pipes' => [], 'pid' => 4242];
    }

    public function isRunning(mixed $resource): bool
    {
        if (++$this->pollCount > 3) {
            $this->running = false;
        }
        $callback = $this->onPoll;
        $this->onPoll = null;
        if ($callback !== null) {
            $callback();
        }

        return $this->running;
    }

    public function exitCode(mixed $resource): int
    {
        return $this->exitStatus;
    }

    public function signal(int $pid, int $signal): void
    {
        $this->signals[] = $signal;
        if ($signal === 9) {
            $this->running = false;
        }
    }

    public function close(mixed $resource): void
    {
        ++$this->closeCount;
    }
}
