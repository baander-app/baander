<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Application\Command\FailInterruptedTranscodeSessionsCommand;
use App\Transcode\Application\CommandHandler\FailInterruptedTranscodeSessionsHandler;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionState;
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
use App\Transcode\Infrastructure\Swoole\TranscodeWorkerExitSubscriber;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Runs a real encoding loop, suspended while its video encoder runs, and then exits the worker:
 * a reload leaves it running, a drain stops it, kills the encoder, releases the loop lock and then
 * ends the loop's session as failed, so a client asking again gets a new session.
 *
 * Each test runs in a separate process: Xdebug's develop mode crashes Swoole's coroutine runtime
 * (exit 139, Swoole 6.2), and PHPUnit starts an isolated test with Xdebug off unless coverage or a
 * debugger needs it.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TranscodeWorkerExitSubscriberTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-transcode-exit-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/output', recursive: true);
        file_put_contents($this->directory . '/source', 'source fixture');
        // The encoder has written its init segment. The loop runs outside the test's call stack,
        // where PHPUnit's error handler cannot handle the warning a missing file would raise.
        file_put_contents($this->directory . '/output/init.mp4', 'init fixture');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testADrainingWorkerStopsItsLoopsKillsTheEncoderReleasesTheLoopLockAndFailsTheSession(): void
    {
        $this->inCoroutine(function (): void {
            $result = $this->drainScenario();

            self::assertSame([SessionState::Failed], $result['savedAfterDrain'], 'The drain fails the session once.');
            self::assertSame([[9]], $result['signalsAtSessionWrite'], 'The session ends after the encoder was killed.');
            self::assertSame([], $result['errors']);
        });
    }

    public function testADrainLeavesTheSessionOfALoopThatLostItsLockToItsNewOwner(): void
    {
        $this->inCoroutine(function (): void {
            $result = $this->drainScenario(ownershipLost: true);

            self::assertSame([], $result['savedAfterDrain']);
            self::assertSame(SessionState::Active, $result['session']->getSessionState());
            self::assertSame(0, $result['releases'], 'A lost lock is not released.');
            self::assertSame([], $result['errors']);
        });
    }

    public function testADatabaseFailureWhileFailingTheSessionIsLoggedAfterTheEncoderStopped(): void
    {
        $this->inCoroutine(function (): void {
            $result = $this->drainScenario(storeFails: true);

            self::assertSame([], $result['savedAfterDrain']);
            self::assertSame([[9]], $result['signalsAtSessionWrite']);
            self::assertSame(1, $result['releases']);
            self::assertSame(['Interrupted transcode sessions were not ended as the worker exits'], $result['errors']);
        });
    }

    private function inCoroutine(\Closure $test): void
    {
        $failure = null;
        Coroutine\run(static function () use ($test, &$failure): void {
            try {
                $test();
            } catch (\Throwable $error) {
                $failure = $error;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @return array{session: TranscodeSession, savedAfterDrain: list<SessionState>, signalsAtSessionWrite: list<list<int>>, releases: int, errors: list<string>}
     */
    private function drainScenario(bool $ownershipLost = false, bool $storeFails = false): array
    {
        $drain = new class implements ServerDrainStateInterface {
            public bool $draining = false;

            public function isDraining(): bool
            {
                return $this->draining;
            }
        };
        $timer = new ExitRenewalTimer();
        $spawner = new ExitProcessSpawner();
        $logger = new ExitRecordingLogger();
        $json = new JsonEncoder();
        $video = Video::create($this->directory . '/source', 'exit-fixture');
        $tier = QualityTier::p720();
        $job = TranscodeJob::create($video->getId(), $tier, $this->directory . '/output');
        $session = TranscodeSession::create(Uuid::generate(), $job->getId(), $video->getId(), AudioProfile::streamingStereo());
        $probe = VideoProbeResult::fromProbeOutput([
            'format' => ['duration' => 40],
            'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720, 'r_frame_rate' => '30/1']],
        ]);
        $job->updateProbeData($probe->jsonSerialize());
        $job->setTotalSegments(10);
        $job->setMeasuredLoudness(['input_i' => -24]);
        $job->markInProgress();
        $session->markPreparing();
        $session->markActive();

        $writes = 0;
        $savedAfterDrain = [];
        $signalsAtSessionWrite = [];
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByUuid')->willReturn($job);
        $jobs->method('save')->willReturnCallback(static function () use (&$writes): void {
            ++$writes;
        });
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $sessions->method('findByJob')->willReturnCallback(
            static fn (Uuid $jobId): array => $jobId->equals($job->getId()) ? [$session] : [],
        );
        $sessions->method('save')->willReturnCallback(
            static function (TranscodeSession $saved) use (&$writes, $drain, &$savedAfterDrain, &$signalsAtSessionWrite, $spawner, $storeFails): void {
                if (!$drain->draining) {
                    ++$writes;

                    return;
                }
                $signalsAtSessionWrite[] = $spawner->signals;
                if ($storeFails) {
                    throw new \RuntimeException('SQLSTATE[08006] could not connect to server');
                }
                $savedAfterDrain[] = $saved->getSessionState();
            },
        );
        $videos = $this->createStub(VideoRepositoryInterface::class);
        $videos->method('findByUuid')->willReturn($video);
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn($this->directory . '/output');
        $storage->method('resolveInitSegmentPath')->willReturn($this->directory . '/output/init.mp4');
        $storage->method('resolveSegmentPath')->willReturn($this->directory . '/output/missing.m4s');
        // The loop runs in a coroutine of its own, where PHPUnit cannot make up a return value.
        $storage->method('exists')->willReturn(false);
        $events = [];
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events): object {
            $events[] = $event::class;

            return $event;
        });
        $ffmpeg = $this->createStub(FFmpegPortInterface::class);
        $encoder = new SegmentEncoder($ffmpeg, $storage, EncoderProfile::software('libx264'));
        $manager = new TranscodeStreamManager($this->createStub(SegmentAvailabilityInterface::class), $storage, $encoder, $logger, $spawner);
        $pool = new CpuProcessPool([], 1, $logger, resultDir: $this->directory);
        $loops = new TranscodeSessionSubscriber(
            $jobs, $sessions, $storage, new TranscodeProcessPool($pool, $logger, $json, EncoderProfile::software('libx264')),
            $ffmpeg, $encoder, $videos, new JobStatePersister($storage, $logger, $this->directory . '/state', $json),
            new SeekSignalBroker(), $dispatcher, $logger, $json, streamManager: $manager, renewalTimer: $timer,
        );
        $lease = $this->createStub(TranscodeLoopLeaseInterface::class);
        $lease->method('getJobId')->willReturn($job->getId());
        $lease->method('renew')->willReturn(true);
        $releases = 0;
        $lease->method('release')->willReturnCallback(static function () use (&$releases): void {
            ++$releases;
        });
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            FailInterruptedTranscodeSessionsCommand::class => [new FailInterruptedTranscodeSessionsHandler($sessions)],
        ]))]);
        $subscriber = new TranscodeWorkerExitSubscriber($drain, $loops, $manager, $bus, $logger);
        $exit = new WorkerExitedEvent((new \ReflectionClass(Server::class))->newInstanceWithoutConstructor(), 0);

        $loopEnded = false;
        Coroutine::create(static function () use ($loops, $session, $lease, &$loopEnded): void {
            (new ReflectionMethod($loops, 'runEncodingLoop'))->invoke($loops, $session->getId(), $lease);
            $loopEnded = true;
        });
        Coroutine::sleep(0.25);
        self::assertTrue($manager->isStreaming($job->getId()), 'The loop must be running its encoder.');
        $writesWhileRunning = $writes;

        // A reload: WorkerExit without the drain leaves the loop and its encoder running.
        $subscriber->onWorkerExited($exit);
        Coroutine::sleep(0.15);
        self::assertTrue($manager->isStreaming($job->getId()));
        self::assertSame([], $spawner->signals);
        self::assertSame(0, $releases);
        self::assertSame([], $timer->cleared);

        if ($ownershipLost) {
            $ownership = (new ReflectionProperty($loops, 'loopOwnership'))->getValue($loops)[$job->getId()->toString()];
            $ownership->markLost();
        }
        $drain->draining = true;
        $subscriber->onWorkerExited($exit);
        $subscriber->onWorkerExited($exit);
        Coroutine::sleep(0.25);

        self::assertTrue($loopEnded, 'The suspended loop ends once it sees it lost ownership.');
        self::assertFalse($manager->isStreaming($job->getId()));
        self::assertSame([9], $spawner->signals, 'The encoder is killed once.');
        self::assertSame(1, $spawner->closeCount);
        self::assertSame($ownershipLost ? 0 : 1, $releases, 'The loop lock is released once.');
        self::assertSame([1], $timer->cleared, 'The lock renewal timer is cleared.');
        self::assertSame([], (new ReflectionProperty($loops, 'loopOwnership'))->getValue($loops));
        self::assertSame(TranscodeStatus::InProgress, $job->getStatus(), 'The job is resumed after the restart.');
        self::assertSame($writesWhileRunning, $writes, 'The stopped loop writes nothing.');
        self::assertSame([], $events);

        return [
            'session' => $session,
            'savedAfterDrain' => $savedAfterDrain,
            'signalsAtSessionWrite' => $signalsAtSessionWrite,
            'releases' => $releases,
            'errors' => $logger->errors,
        ];
    }
}

final class ExitRecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $errors = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ($level === 'error') {
            $this->errors[] = (string) $message;
        }
    }
}

final class ExitRenewalTimer implements LoopLockRenewalTimerInterface
{
    /** @var list<int> */
    public array $cleared = [];

    public function tick(int $intervalMs, \Closure $callback): int
    {
        return 1;
    }

    public function clear(int $id): void
    {
        $this->cleared[] = $id;
    }
}

final class ExitProcessSpawner implements ProcessSpawnerInterface
{
    /** @var list<int> */
    public array $signals = [];
    public int $closeCount = 0;
    private bool $running = true;

    public function spawn(array $command): array
    {
        return ['resource' => new \stdClass(), 'pipes' => [], 'pid' => 4242];
    }

    public function isRunning(mixed $resource): bool
    {
        return $this->running;
    }

    public function exitCode(mixed $resource): int
    {
        return -1;
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
