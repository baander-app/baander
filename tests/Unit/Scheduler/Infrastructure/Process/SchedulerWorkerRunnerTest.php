<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Infrastructure\Process;

use App\Scheduler\Application\DTO\SchedulerOccurrenceDispatchClaim;
use App\Scheduler\Application\Port\SchedulerOccurrenceDispatchStoreInterface;
use App\Scheduler\Application\Port\SchedulerOccurrenceMaterializerInterface;
use App\Scheduler\Application\Port\SchedulerOccurrencePublisherInterface;
use App\Scheduler\Application\Port\SchedulerRecoveryScheduleStoreInterface;
use App\Scheduler\Application\Port\SchedulerWorkerAuthorityInterface;
use App\Scheduler\Application\Service\SchedulerOccurrenceRelay;
use App\Scheduler\Application\Service\SchedulerRecoveryPoller;
use App\Scheduler\Infrastructure\Process\SchedulerWorkerRunner;
use App\Shared\Domain\Model\Uuid;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SchedulerWorkerRunnerTest extends TestCase
{
    public function testSignalPollTimeoutIgnoresAnUnrelatedPreviousPcntlError(): void
    {
        $status = 0;
        self::assertSame(-1, pcntl_waitpid(getmypid(), $status, WNOHANG));
        self::assertSame(PCNTL_ECHILD, pcntl_get_last_error());
        $callerHandler = static fn (): bool => false;
        set_error_handler($callerHandler);
        try {
            $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
            $schedules->expects(self::once())->method('claimPendingJobs')->willReturnCallback(static function (): array {
                $mask = [];
                self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGTERM], $mask));
                self::assertContains(SIGTERM, $mask);
                self::assertTrue(posix_kill(getmypid(), SIGTERM));
                return [];
            });
            $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
            $dispatches->expects(self::never())->method('claimPending');
            $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
            $authority->expects(self::once())->method('assertActive');
            $this->runner($schedules, $dispatches, $authority)->runUntilSignalled();
            $restoredHandler = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            self::assertSame($callerHandler, $restoredHandler);
        } finally {
            restore_error_handler();
        }
    }

    #[DataProvider('stopSignals')]
    public function testSignalDuringRecoveryStopsBeforeRelayAndRestoresOriginalMask(int $signal): void
    {
        self::assertTrue(function_exists('pcntl_sigprocmask'));
        self::assertTrue(function_exists('pcntl_sigtimedwait'));
        self::assertTrue(function_exists('posix_kill'));
        $originalMask = [];
        self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $originalMask));
        try {
            $entryMask = [];
            self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $entryMask));
            $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
            $schedules->expects(self::once())->method('claimPendingJobs')->willReturnCallback(static function () use ($signal): array {
                $currentMask = [];
                self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $currentMask));
                self::assertContains(SIGTERM, $currentMask, 'Verify blocking before signalling this test process.');
                self::assertContains(SIGINT, $currentMask, 'Verify blocking before signalling this test process.');
                self::assertContains(SIGUSR1, $currentMask);
                self::assertTrue(posix_kill(getmypid(), $signal));
                return [];
            });
            $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
            $dispatches->expects(self::never())->method('claimPending');
            $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
            $authority->expects(self::once())->method('assertActive');
            $this->runner($schedules, $dispatches, $authority)->runUntilSignalled();
            $restoredMask = [];
            self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $restoredMask));
            self::assertSame($entryMask, $restoredMask);
        } finally {
            self::assertTrue(pcntl_sigprocmask(SIG_SETMASK, $originalMask));
        }
    }

    /** @return iterable<string, array{int}> */
    public static function stopSignals(): iterable
    {
        yield 'TERM' => [SIGTERM];
        yield 'INT' => [SIGINT];
    }

    public function testSignalMaskRestoredWhenAuthorityFails(): void
    {
        self::assertTrue(function_exists('pcntl_sigprocmask'));
        $originalMask = [];
        self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $originalMask));
        try {
            $entryMask = [];
            self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $entryMask));
            $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
            $schedules->expects(self::never())->method('claimPendingJobs');
            $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
            $dispatches->expects(self::never())->method('claimPending');
            $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
            $error = new \RuntimeException('Fixture authority lost.');
            $authority->expects(self::once())->method('assertActive')->willReturnCallback(static function () use ($error): void {
                $currentMask = [];
                self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $currentMask));
                self::assertContains(SIGTERM, $currentMask);
                self::assertContains(SIGINT, $currentMask);
                throw $error;
            });
            try {
                $this->runner($schedules, $dispatches, $authority)->runUntilSignalled();
                self::fail('Authority failure must propagate from the signal entrypoint.');
            } catch (\RuntimeException $actual) {
                self::assertSame($error, $actual);
            }
            $restoredMask = [];
            self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $restoredMask));
            self::assertSame($entryMask, $restoredMask);
        } finally {
            self::assertTrue(pcntl_sigprocmask(SIG_SETMASK, $originalMask));
        }
    }

    public function testInitialPassesCheckFreshAuthorityAndUseBoundedDefaults(): void
    {
        $events = [];
        $stop = false;
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $authority->expects(self::exactly(2))->method('assertActive')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'authority';
        });
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects(self::once())->method('claimPendingJobs')->with(10, 60)->willReturnCallback(static function () use (&$events): array {
            $events[] = 'recovery';
            return [];
        });
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::once())->method('claimPending')->with(100, 60)->willReturnCallback(static function () use (&$events, &$stop): array {
            $events[] = 'relay';
            $stop = true;
            return [];
        });
        $this->runner($schedules, $dispatches, $authority)->run(static function () use (&$stop): bool { return $stop; });
        self::assertSame(['authority', 'recovery', 'authority', 'relay'], $events);
    }

    public function testStopBeforeWorkAvoidsAuthorityAndAllPhases(): void
    {
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects(self::never())->method('claimPendingJobs');
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::never())->method('claimPending');
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $authority->expects(self::never())->method('assertActive');
        $this->runner($schedules, $dispatches, $authority)->run(static fn (): bool => true);
    }

    public function testStopDuringRecoveryPreventsRelay(): void
    {
        $stop = false;
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects(self::once())->method('claimPendingJobs')->willReturnCallback(static function () use (&$stop): array {
            $stop = true;
            return [];
        });
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::never())->method('claimPending');
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $authority->expects(self::once())->method('assertActive');
        $this->runner($schedules, $dispatches, $authority)->run(static function () use (&$stop): bool { return $stop; });
    }

    public function testPoisonRecoveryDoesNotStarveRelayAndLogsNoExceptionPayload(): void
    {
        $stop = false;
        $job = Uuid::v7();
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects(self::once())->method('claimPendingJobs')->willReturn([$job]);
        $materializer = $this->createMock(SchedulerOccurrenceMaterializerInterface::class);
        $materializer->expects(self::once())->method('materialize')->with($job, 60)->willThrowException(new \RuntimeException('secret recovery payload'));
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::once())->method('claimPending')->willReturnCallback(static function () use (&$stop): array {
            $stop = true;
            return [];
        });
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $authority->expects(self::exactly(2))->method('assertActive');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Scheduler worker recovery pass failed.', []);
        $this->runner($schedules, $dispatches, $authority, $materializer, logger: $logger)->run(static function () use (&$stop): bool { return $stop; });
    }

    public function testSlowRecoveryAndFailedRelayKeepIndependentCompletionCadenceWithoutCatchupBurst(): void
    {
        $now = 0.0;
        $stop = false;
        $events = [];
        $waits = [];
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects(self::exactly(2))->method('claimPendingJobs')->willReturnCallback(static function () use (&$now, &$events): array {
            $events[] = ['recovery', $now];
            $now += 2.0;
            return [];
        });
        $claims = [new SchedulerOccurrenceDispatchClaim(Uuid::v7(), Uuid::v7())];
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::exactly(2))->method('claimPending')->willReturnCallback(static function () use (&$now, &$events, &$stop, $claims): array {
            $events[] = ['relay', $now];
            if (count($events) === 4) {
                $stop = true;
            }
            return $claims;
        });
        $publisher = $this->createMock(SchedulerOccurrencePublisherInterface::class);
        $publisher->expects(self::exactly(2))->method('publish')->willThrowException(new \RuntimeException('secret relay payload'));
        $dispatches->expects(self::never())->method('markPublished');
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $authority->expects(self::exactly(4))->method('assertActive');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('error')->with('Scheduler worker relay pass failed.', []);
        $clock = static function () use (&$now): float { return $now; };
        $wait = static function (float $seconds) use (&$now, &$waits): void {
            self::assertGreaterThan(0.0, $seconds);
            self::assertLessThanOrEqual(0.1, $seconds);
            $waits[] = $seconds;
            $now += $seconds;
        };
        $this->runner($schedules, $dispatches, $authority, publisher: $publisher, logger: $logger, clock: $clock, wait: $wait)
            ->run(static function () use (&$stop): bool { return $stop; });
        self::assertSame([['recovery', 0.0], ['relay', 2.0]], array_slice($events, 0, 2));
        self::assertEqualsWithDelta(3.0, $events[2][1], 0.000001);
        self::assertEqualsWithDelta(5.0, $events[3][1], 0.000001);
        self::assertNotEmpty($waits, 'An idle interval must wait instead of spinning.');
        self::assertEqualsWithDelta(1.0, array_sum($waits), 0.000001);
    }

    #[DataProvider('authorityLossPhases')]
    public function testAuthorityLossPropagatesBeforeItsPhase(bool $duringRelay): void
    {
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects($duringRelay ? self::once() : self::never())->method('claimPendingJobs')->willReturn([]);
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::never())->method('claimPending');
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $checks = 0;
        $error = new \RuntimeException('authority lost');
        $authority->expects(self::exactly($duringRelay ? 2 : 1))->method('assertActive')->willReturnCallback(static function () use (&$checks, $duringRelay, $error): void {
            if (++$checks === ($duringRelay ? 2 : 1)) {
                throw $error;
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $this->expectExceptionObject($error);
        $this->runner($schedules, $dispatches, $authority, logger: $logger)->run(static fn (): bool => false);
    }

    /** @return iterable<string, array{bool}> */
    public static function authorityLossPhases(): iterable
    {
        yield 'before recovery' => [false];
        yield 'before relay' => [true];
    }

    #[DataProvider('authorityLossPhases')]
    public function testStopDuringAuthorityCheckPreventsItsPhase(bool $duringRelay): void
    {
        $stop = false;
        $checks = 0;
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects($duringRelay ? self::once() : self::never())->method('claimPendingJobs')->willReturn([]);
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::never())->method('claimPending');
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $authority->expects(self::exactly($duringRelay ? 2 : 1))->method('assertActive')->willReturnCallback(static function () use (&$checks, &$stop, $duringRelay): void {
            if (++$checks === ($duringRelay ? 2 : 1)) {
                $stop = true;
            }
        });
        $this->runner($schedules, $dispatches, $authority)->run(static function () use (&$stop): bool { return $stop; });
    }

    #[DataProvider('invalidClockValues')]
    public function testInvalidInitialClockPreventsAllAdmission(float $now): void
    {
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects(self::never())->method('claimPendingJobs');
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::never())->method('claimPending');
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $authority->expects(self::never())->method('assertActive');
        $this->expectException(\InvalidArgumentException::class);
        $this->runner($schedules, $dispatches, $authority, clock: static fn (): float => $now)->run(static fn (): bool => false);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidClockValues(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    public function testBackwardCompletionClockPreventsRelay(): void
    {
        $reads = 0;
        $schedules = $this->createMock(SchedulerRecoveryScheduleStoreInterface::class);
        $schedules->expects(self::once())->method('claimPendingJobs')->willReturn([]);
        $dispatches = $this->createMock(SchedulerOccurrenceDispatchStoreInterface::class);
        $dispatches->expects(self::never())->method('claimPending');
        $authority = $this->createMock(SchedulerWorkerAuthorityInterface::class);
        $authority->expects(self::once())->method('assertActive');
        $clock = static function () use (&$reads): float { return ++$reads === 1 ? 1.0 : 0.5; };
        $this->expectException(\InvalidArgumentException::class);
        $this->runner($schedules, $dispatches, $authority, clock: $clock)->run(static fn (): bool => false);
    }

    /**
     * @param Closure():float|null $clock
     * @param Closure(float):void|null $wait
     */
    private function runner(
        SchedulerRecoveryScheduleStoreInterface $schedules,
        SchedulerOccurrenceDispatchStoreInterface $dispatches,
        SchedulerWorkerAuthorityInterface $authority,
        ?SchedulerOccurrenceMaterializerInterface $materializer = null,
        ?SchedulerOccurrencePublisherInterface $publisher = null,
        ?LoggerInterface $logger = null,
        ?Closure $clock = null,
        ?Closure $wait = null,
    ): SchedulerWorkerRunner {
        return new SchedulerWorkerRunner(
            new SchedulerRecoveryPoller($schedules, $materializer ?? $this->createStub(SchedulerOccurrenceMaterializerInterface::class)),
            new SchedulerOccurrenceRelay($dispatches, $publisher ?? $this->createStub(SchedulerOccurrencePublisherInterface::class)),
            $authority, $logger ?? new NullLogger(), $clock ?? static fn (): float => 0.0,
            $wait ?? static function (): void { self::fail('This test must finish before waiting.'); },
        );
    }
}
