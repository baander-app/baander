<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use App\Transcode\Application\Port\TranscodeLoopLockInterface;
use App\Transcode\Application\Port\TranscodeLoopStarterInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\Repository\TranscodeSessionRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Swoole\GracefulRestartHandler;
use App\Transcode\Infrastructure\Swoole\JobStatePersister;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class GracefulRestartHandlerLeaseTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-restart-lease-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function outcomes(): iterable
    {
        yield 'accepted' => ['accepted'];
        yield 'denied lease' => ['denied'];
        yield 'budget veto' => ['event'];
        yield 'starter rejects' => ['starter'];
        yield 'no live session' => ['no session'];
    }

    #[DataProvider('outcomes')]
    public function testRecoveryKeepsLeaseWithAcquisitionAndCountsOnlyAcceptedStarts(string $outcome): void
    {
        $logger = new NullLogger();
        $json = new JsonEncoder();
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/tmp/baander-transcode');
        $job->markInProgress();
        $finished = TranscodeSession::create(Uuid::generate(), $job->getId(), $job->getVideoId(), AudioProfile::streamingStereo());
        $finished->markFailed();
        $session = TranscodeSession::create(Uuid::generate(), $job->getId(), $job->getVideoId(), AudioProfile::streamingStereo());
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $persister = new JobStatePersister($storage, $logger, $this->directory, $json);
        $persister->persist($job);
        $jobs = $this->createMock(TranscodeJobPortInterface::class);
        $jobs->expects(self::once())->method('findByUuid')->with($job->getId())->willReturn($job);
        $sessions = $this->createMock(TranscodeSessionRepositoryInterface::class);
        $sessions->expects(self::once())->method('findByJob')->with($job->getId())
            ->willReturn($outcome === 'no session' ? [$finished] : [$finished, $session]);
        $lock = $this->createMock(TranscodeLoopLockInterface::class);
        $lease = $this->createMock(TranscodeLoopLeaseInterface::class);
        $starter = $this->createMock(TranscodeLoopStarterInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);
        $error = new \RuntimeException('restart rejected');
        $dispatched = false;
        $lock->expects($outcome === 'no session' ? self::never() : self::once())->method('acquire')
            ->with($job->getId(), 30)->willReturn($outcome === 'denied' ? null : $lease);
        $lease->expects(in_array($outcome, ['event', 'starter'], true) ? self::once() : self::never())->method('release');
        if (in_array($outcome, ['denied', 'no session'], true)) {
            $events->expects(self::never())->method('dispatch');
            $starter->expects(self::never())->method('start');
        } else {
            $events->expects(self::once())->method('dispatch')->willReturnCallback(
                function (object $event) use ($job, $session, $outcome, $error, &$dispatched): object {
                    self::assertInstanceOf(TranscodeSessionAttached::class, $event);
                    self::assertTrue($event->getJobId()->equals($job->getId()));
                    self::assertTrue($event->getSessionId()->equals($session->getId()));
                    $dispatched = true;
                    if ($outcome === 'event') {
                        throw $error;
                    }
                    return $event;
                },
            );
            $starter->expects($outcome === 'event' ? self::never() : self::once())->method('start')
                ->with($session->getId(), $lease)->willReturnCallback(function () use ($outcome, $error, &$dispatched): void {
                    self::assertTrue($dispatched);
                    if ($outcome === 'starter') {
                        throw $error;
                    }
                });
        }
        $pool = new TranscodeProcessPool(new CpuProcessPool([], 1, $logger), $logger, $json, EncoderProfile::software());
        $handler = new GracefulRestartHandler($jobs, $sessions, $persister, $pool, $events, $lock, $starter, $logger);
        try {
            if (in_array($outcome, ['event', 'starter'], true)) {
                $this->expectExceptionObject($error);
            }
            $count = $handler->resumePersistedJobs();
            self::assertSame($outcome === 'accepted' ? 1 : 0, $count);
        } finally {
            self::assertFileExists($this->directory . '/' . $job->getPublicId()->toString() . '.json');
        }
    }
}
