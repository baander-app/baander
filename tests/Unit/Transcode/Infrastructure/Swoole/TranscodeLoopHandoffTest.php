<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Swoole\JobStatePersister;
use App\Transcode\Infrastructure\Swoole\SeekSignalBroker;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use App\Transcode\Infrastructure\Swoole\TranscodeSessionSubscriber;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Coroutine\Channel;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TranscodeLoopHandoffTest extends TestCase
{
    private CpuProcessPool $pool;
    private ?\Swoole\Process $worker = null;
    private TranscodeSessionSubscriber $runtime;
    private TranscodeJobPortInterface&\PHPUnit\Framework\MockObject\MockObject $jobs;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-handoff-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $logger = new NullLogger();
        $json = new JsonEncoder();
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $ffmpeg = $this->createStub(FFmpegPortInterface::class);
        $this->jobs = $this->createMock(TranscodeJobPortInterface::class);
        $this->pool = new CpuProcessPool([], 1, $logger, 16, $this->directory);
        $this->runtime = new TranscodeSessionSubscriber(
            $this->jobs, $this->createStub(TranscodeSessionPortInterface::class), $storage,
            new TranscodeProcessPool($this->pool, $logger, $json, EncoderProfile::software('libx264')),
            $ffmpeg, new SegmentEncoder($ffmpeg, $storage, EncoderProfile::software('libx264')),
            $this->createStub(VideoRepositoryInterface::class),
            new JobStatePersister($storage, $logger, $this->directory, $json),
            new SeekSignalBroker(), $this->createStub(EventDispatcherInterface::class), $logger, $json,
            coWrapper: new CoWrapper(new ServicePoolContainer([]), new Swoole()),
        );
    }

    protected function tearDown(): void
    {
        if ($this->worker !== null) {
            $this->worker->write('stop');
            \Swoole\Process::wait(true);
            $this->worker->close();
        }
        rmdir($this->directory);
    }

    public function testUnavailablePoolRejectsWithoutTakingLeaseOwnership(): void
    {
        $lease = $this->createMock(TranscodeLoopLeaseInterface::class);
        $lease->expects(self::never())->method('renew');
        $lease->expects(self::never())->method('release');
        $this->jobs->expects(self::never())->method('findByUuid');
        $this->expectException(TranscodeStartupUnavailableException::class);

        $this->runtime->start(Uuid::generate(), $lease);
    }

    public function testDuplicateLocalStartRejectsNewLeaseAndAcceptedLoopCleansUpItsOwnLease(): void
    {
        $this->startReadinessWorker();
        $jobId = Uuid::generate();
        $accepted = $this->createMock(TranscodeLoopLeaseInterface::class);
        $accepted->method('getJobId')->willReturn($jobId);
        $renewals = [];
        $accepted->expects(self::once())->method('renew')->willReturnCallback(static function (int $ttl) use (&$renewals): bool {
            $renewals[] = $ttl;
            return true;
        });
        $accepted->expects(self::once())->method('release');
        $rejected = $this->createMock(TranscodeLoopLeaseInterface::class);
        $rejected->method('getJobId')->willReturn($jobId);
        $rejected->expects(self::never())->method('renew');
        $rejected->expects(self::never())->method('release');
        $failure = null;
        \Swoole\Coroutine\run(function () use ($accepted, $rejected, &$failure): void {
            $gate = new Channel(1);
            $this->jobs->expects(self::once())->method('findByUuid')->willReturnCallback(static function () use ($gate): null {
                $gate->pop(2);
                return null;
            });
            try {
                $this->runtime->start(Uuid::generate(), $accepted);
                try {
                    $this->runtime->start(Uuid::generate(), $rejected);
                    self::fail('A duplicate local loop was accepted.');
                } catch (TranscodeStartupUnavailableException) {
                    // Rejection leaves the second handle owned by the caller.
                }
            } catch (\Throwable $error) {
                $failure = $error;
            } finally {
                $gate->push(true);
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
        self::assertSame([30], $renewals);
        self::assertSame([], (new \ReflectionProperty($this->runtime, 'runningJobs'))->getValue($this->runtime));
    }
    public function testSchedulingFailureLeavesLeaseWithCallerAndAllowsRetry(): void
    {
        $this->startReadinessWorker();
        $lease = $this->createMock(TranscodeLoopLeaseInterface::class);
        $lease->method('getJobId')->willReturn(Uuid::generate());
        $lease->expects(self::once())->method('renew')->willReturn(true);
        $lease->expects(self::once())->method('release');
        $this->jobs->expects(self::once())->method('findByUuid')->willReturn(null);
        $sessionId = Uuid::generate();
        $failure = null;
        $warnings = [];
        \Swoole\Coroutine::set(['max_coroutine' => 1]);
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if ($severity === E_WARNING && str_contains($message, 'exceed max number of coroutine')) {
                $warnings[] = $message;
                return true;
            }
            return false;
        });
        try {
            \Swoole\Coroutine\run(function () use ($sessionId, $lease, &$failure): void {
                try {
                    $this->runtime->start($sessionId, $lease);
                } catch (\Throwable $error) {
                    $failure = $error;
                }
            });
        } finally {
            restore_error_handler();
            \Swoole\Coroutine::set(['max_coroutine' => 8]);
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('Unable to create coroutine.', $failure->getMessage());
        self::assertCount(1, $warnings);
        self::assertSame([], (new \ReflectionProperty($this->runtime, 'runningJobs'))->getValue($this->runtime));
        \Swoole\Coroutine\run(function () use ($sessionId, $lease): void {
            $this->runtime->start($sessionId, $lease);
        });
    }

    private function startReadinessWorker(): void
    {
        // Supply a real, controlled process for the readiness dependency. This
        // test exercises handoff, not the CPU pool's dispatch/shutdown lifecycle.
        $this->worker = new \Swoole\Process(static function (\Swoole\Process $worker): void {
            $worker->read();
        });
        self::assertGreaterThan(0, $this->worker->start());
        (new \ReflectionProperty($this->pool, 'booted'))->setValue($this->pool, true);
        (new \ReflectionProperty($this->pool, 'workers'))->setValue($this->pool, [$this->worker]);
        $health = new \Swoole\Table(2);
        foreach (['generation', 'alive', 'pid'] as $column) {
            $health->column($column, \Swoole\Table::TYPE_INT);
        }
        self::assertTrue($health->create());
        $health->set('pool', ['generation' => 0, 'alive' => 1, 'pid' => 0]);
        $health->set('0', ['generation' => 0, 'alive' => 1, 'pid' => $this->worker->pid]);
        (new \ReflectionProperty($this->pool, 'healthTable'))->setValue($this->pool, $health);
        self::assertTrue($this->pool->isRunning());
    }

}
