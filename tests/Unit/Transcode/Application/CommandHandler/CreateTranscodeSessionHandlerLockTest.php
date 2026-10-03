<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CreateTranscodeSessionHandler;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopLockInterface;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use App\Transcode\Application\Port\TranscodeLoopStarterInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CreateTranscodeSessionHandlerLockTest extends TestCase
{
    private TranscodeJobPortInterface&MockObject $jobs;
    private TranscodeSessionPortInterface&MockObject $sessions;
    private TranscodeLoopLockInterface&MockObject $lock;
    private TranscodeLoopLeaseInterface&MockObject $lease;
    private TranscodeLoopStarterInterface&MockObject $starter;
    private EventDispatcherInterface&MockObject $events;
    private TranscodeJob $job;
    private TranscodeSession $session;
    private CreateTranscodeSessionCommand $command;
    private CreateTranscodeSessionHandler $handler;

    protected function setUp(): void
    {
        $userId = Uuid::generate();
        $videoId = Uuid::generate();
        $tier = QualityTier::p720();
        $audio = AudioProfile::streamingStereo();
        $this->job = TranscodeJob::create($videoId, $tier, '/tmp/baander-transcode');
        $this->session = TranscodeSession::create($userId, $this->job->getId(), $videoId, $audio);
        $this->command = new CreateTranscodeSessionCommand($userId, $videoId, $tier, $audio, audioLanguages: ['en']);
        $this->jobs = $this->createMock(TranscodeJobPortInterface::class);
        $this->sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $this->lock = $this->createMock(TranscodeLoopLockInterface::class);
        $this->lease = $this->createMock(TranscodeLoopLeaseInterface::class);
        $this->starter = $this->createMock(TranscodeLoopStarterInterface::class);
        $this->events = $this->createMock(EventDispatcherInterface::class);
        $storage = $this->createMock(TranscodeStoragePortInterface::class);
        $storage->expects(self::once())->method('resolveJobDirectory')->with($videoId, $tier)->willReturn('/tmp/baander-transcode');
        $this->jobs->expects(self::once())->method('getOrCreateJob')
            ->with($videoId, $tier, '/tmp/baander-transcode', ['en'])->willReturn($this->job);
        $this->handler = new CreateTranscodeSessionHandler($this->jobs, $this->sessions, $storage, $this->events, $this->lock, $this->starter);
    }

    public function testDeniedLockWithoutLiveSessionFailsWithoutWritesOrRelease(): void
    {
        $before = clone $this->job->getState();
        $this->expectNoWrites();
        $this->sessions->method('findByJob')->willReturn([]);
        $this->lock->expects(self::once())->method('acquire')->with($this->job->getId(), 30)->willReturn(null);
        $this->lease->expects(self::never())->method('release');
        $this->expectException('App\\Transcode\\Application\\Exception\\TranscodeStartupUnavailableException');

        try {
            ($this->handler)($this->command);
        } finally {
            self::assertEquals($before, $this->job->getState());
        }
    }

    /** @return iterable<string, array{TranscodeStatus}> */
    public static function retryableStates(): iterable
    {
        yield 'failed' => [TranscodeStatus::Failed];
        yield 'cancelled' => [TranscodeStatus::Cancelled];
    }

    #[DataProvider('retryableStates')]
    public function testDeniedLockReusesAppearingSessionWithoutMutatingRetryableJob(TranscodeStatus $status): void
    {
        $this->makeRetryable($status);
        $before = clone $this->job->getState();
        $this->expectNoWrites();
        $this->sessions->expects(self::exactly(2))->method('findByJob')->willReturnOnConsecutiveCalls([], [$this->session]);
        $this->lock->expects(self::once())->method('acquire')->willReturn(null);
        $this->lease->expects(self::never())->method('release');

        self::assertSame($this->session, ($this->handler)($this->command));
        self::assertEquals($before, $this->job->getState());
    }

    public function testAcquiredLockRechecksLiveSessionAndReleasesWithoutWrites(): void
    {
        $this->makeRetryable(TranscodeStatus::Failed);
        $before = clone $this->job->getState();
        $this->expectNoWrites();
        $this->sessions->expects(self::exactly(2))->method('findByJob')->willReturnOnConsecutiveCalls([], [$this->session]);
        $this->lock->expects(self::once())->method('acquire')->willReturn($this->lease);
        $this->lease->expects(self::once())->method('release');

        self::assertSame($this->session, ($this->handler)($this->command));
        self::assertEquals($before, $this->job->getState());
    }

    /** @return iterable<string, array{TranscodeStatus}> */
    public static function startupStates(): iterable
    {
        yield 'new job' => [TranscodeStatus::Pending];
        yield from self::retryableStates();
    }

    #[DataProvider('startupStates')]
    public function testSuccessfulStartupMutatesOnlyAfterLockAndDispatchesOneSession(TranscodeStatus $status): void
    {
        if ($status !== TranscodeStatus::Pending) {
            $this->makeRetryable($status);
        }
        $before = clone $this->job->getState();
        $this->sessions->expects(self::exactly($status === TranscodeStatus::Pending ? 3 : 2))->method('findByJob')->willReturn([]);
        $this->lock->expects(self::once())->method('acquire')->with($this->job->getId(), 30)
            ->willReturnCallback(function () use ($before): TranscodeLoopLeaseInterface {
                self::assertEquals($before, $this->job->getState());

                return $this->lease;
            });
        $this->lease->expects(self::never())->method('release');
        $this->jobs->expects(self::once())->method('save')->with($this->job);
        $this->sessions->expects(self::never())->method('save');
        $this->sessions->expects(self::once())->method('createSession')
            ->with($this->command->getUserId(), $this->job->getId(), $this->command->getVideoId(),
                $this->command->getAudioProfile(), $this->command->getPriority(), ['en'])
            ->willReturn($this->session);
        $dispatched = new \ArrayObject();
        $this->starter->expects(self::once())->method('start')->with($this->session->getId(), $this->lease)
            ->willReturnCallback(function () use ($dispatched): void { self::assertCount(1, $dispatched); });
        $this->events->expects(self::once())->method('dispatch')->with(self::callback(
            fn(object $event): bool => $event instanceof TranscodeSessionAttached
                && $event->getSessionId()->equals($this->session->getId())
                && $event->getJobId()->equals($this->job->getId()),
        ))->willReturnCallback(function (object $event) use ($dispatched): object {
            $dispatched->append($event);
            return $event;
        });

        self::assertSame($this->session, ($this->handler)($this->command));
        self::assertSame(TranscodeStatus::Pending, $this->job->getStatus());
        self::assertSame(['en'], $this->job->getAudioTrackLanguages());
        self::assertSame(1, $this->job->getReferenceCount());
    }

    /** @return iterable<string, array{string}> */
    public static function failedStartupBoundaries(): iterable
    {
        yield 'session recheck' => ['recheck'];
        yield 'job persistence' => ['job'];
        yield 'session creation' => ['session'];
        yield 'budget or event listener' => ['event'];
        yield 'starter rejection' => ['starter'];
    }

    #[DataProvider('failedStartupBoundaries')]
    public function testFailureBeforeHandoffReleasesCapturedLease(string $boundary): void
    {
        $error = new \RuntimeException('startup failed');
        $this->lock->expects(self::once())->method('acquire')->willReturn($this->lease);
        $this->lease->expects(self::once())->method('release');
        $reads = 0;
        $this->sessions->expects(self::exactly($boundary === 'recheck' ? 2 : 3))->method('findByJob')->willReturnCallback(function () use ($boundary, $error, &$reads): array {
            if (++$reads === 2 && $boundary === 'recheck') {
                throw $error;
            }
            return [];
        });
        if ($boundary === 'job') {
            $this->jobs->method('save')->willThrowException($error);
        }
        if ($boundary === 'session') {
            $this->sessions->method('createSession')->willThrowException($error);
        } else {
            $this->sessions->method('createSession')->willReturn($this->session);
        }
        if ($boundary === 'event') {
            $this->events->expects(self::once())->method('dispatch')->willThrowException($error);
        } else {
            $this->events->expects($boundary === 'starter' ? self::once() : self::never())->method('dispatch')->willReturnArgument(0);
        }
        if ($boundary === 'starter') {
            $this->starter->expects(self::once())->method('start')->with($this->session->getId(), $this->lease)
                ->willThrowException($error);
        } else {
            $this->starter->expects(self::never())->method('start');
        }
        $this->expectExceptionObject($error);
        ($this->handler)($this->command);
    }

    private function expectNoWrites(): void
    {
        $this->jobs->expects(self::never())->method('save');
        $this->sessions->expects(self::never())->method('createSession');
        $this->sessions->expects(self::never())->method('save');
        $this->events->expects(self::never())->method('dispatch');
        $this->starter->expects(self::never())->method('start');
    }

    private function makeRetryable(TranscodeStatus $status): void
    {
        if ($status === TranscodeStatus::Failed) {
            $this->job->markFailed('original failure');
        } else {
            $this->job->markCancelled();
        }
    }
}
