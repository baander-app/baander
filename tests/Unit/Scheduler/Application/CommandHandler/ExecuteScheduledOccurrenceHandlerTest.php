<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Application\CommandHandler;

use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledJobHandler;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledOccurrenceHandler;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Application\Port\SchedulerOccurrenceExecutionStoreInterface;
use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Scheduler\Domain\Model\SchedulableConsoleCommandInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExecuteScheduledOccurrenceHandlerTest extends TestCase
{
    private function job(): ScheduledJob
    {
        return ScheduledJob::create('Occurrence', '* * * * *', JobType::Messenger, OccurrenceTestMessage::class, parameters: ['value' => 1.0]);
    }

    private function occurrence(ScheduledJob $job): SchedulerOccurrence
    {
        return new SchedulerOccurrence(Uuid::generate(), $job->getId(), new \DateTimeImmutable('2026-10-03T12:00:00Z'), $job->getJobType(), $job->getCommand(), $job->getParameters());
    }

    private function handler(SchedulerOccurrenceExecutionStoreInterface $store, ScheduledJobPortInterface $jobs, MessageBusInterface $bus, ?SchedulerRegistry $registry = null): ExecuteScheduledOccurrenceHandler
    {
        $redis = $this->createMock(RedisClientFactory::class);
        $redis->expects(self::never())->method('borrow');
        $pool = $this->createMock(CpuProcessPoolInterface::class);
        $pool->expects(self::never())->method('dispatch');

        return new ExecuteScheduledOccurrenceHandler($store, new ExecuteScheduledJobHandler(
            $jobs, $registry ?? new SchedulerRegistry([new OccurrenceTestMessage()], []), $bus, $pool, $redis, new NullLogger(),
        ));
    }

    public function testFirstClaimExecutesOnceAndDuplicateHasNoEffects(): void
    {
        $job = $this->job();
        $occurrence = $this->occurrence($job);
        $store = $this->createMock(SchedulerOccurrenceExecutionStoreInterface::class);
        $attempt = null;
        $store->expects(self::exactly(2))->method('begin')->willReturnCallback(function (Uuid $id, Uuid $candidate) use ($occurrence, &$attempt): ?SchedulerOccurrence {
            self::assertTrue($id->equals($occurrence->id));
            self::assertSame('7', $candidate->toString()[14]);
            if ($attempt !== null) {
                self::assertFalse($candidate->equals($attempt));
                return null;
            }
            $attempt = $candidate;
            return $occurrence;
        });
        $store->expects(self::once())->method('markReturned')->willReturnCallback(function (Uuid $id, Uuid $owner) use ($occurrence, &$attempt): bool {
            self::assertTrue($id->equals($occurrence->id));
            self::assertSame($attempt, $owner);
            return true;
        });
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->expects(self::once())->method('getById')->willReturn($job);
        $jobs->expects(self::exactly(2))->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(function (OccurrenceTestMessage $message): Envelope {
            self::assertSame(1.0, $message->value);
            return new Envelope($message);
        });
        $handler = $this->handler($store, $jobs, $bus);
        $handler(new ExecuteScheduledOccurrenceCommand($occurrence->id));
        $handler(new ExecuteScheduledOccurrenceCommand($occurrence->id));
        self::assertSame('dispatched', $job->getLastResult());
    }

    public function testFailedBeginDoesNotReadScheduleOrInvoke(): void
    {
        $error = new \RuntimeException('Commit outcome unknown');
        $store = $this->createMock(SchedulerOccurrenceExecutionStoreInterface::class);
        $store->expects(self::once())->method('begin')->willThrowException($error);
        $store->expects(self::never())->method('markReturned');
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->expects(self::never())->method('getById');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        try {
            ($this->handler($store, $jobs, $bus))(new ExecuteScheduledOccurrenceCommand(Uuid::generate()));
            self::fail('Expected original claim failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testThrownExecutionLeavesClaimWithoutReturnReceipt(): void
    {
        $job = $this->job();
        $occurrence = $this->occurrence($job);
        $error = new \RuntimeException('Persistence failed');
        $store = $this->createMock(SchedulerOccurrenceExecutionStoreInterface::class);
        $store->method('begin')->willReturn($occurrence);
        $store->expects(self::never())->method('markReturned');
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->method('getById')->willReturn($job);
        $jobs->expects(self::once())->method('save')->willThrowException($error);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        try {
            ($this->handler($store, $jobs, $bus))(new ExecuteScheduledOccurrenceCommand($occurrence->id));
            self::fail('Expected original execution failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    public function testFailedReturnReceiptCannotReenterConsumedOccurrence(): void
    {
        $job = $this->job();
        $occurrence = $this->occurrence($job);
        $store = $this->createMock(SchedulerOccurrenceExecutionStoreInterface::class);
        $store->expects(self::exactly(2))->method('begin')->willReturnOnConsecutiveCalls($occurrence, null);
        $store->expects(self::once())->method('markReturned')->willReturn(false);
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->expects(self::once())->method('getById')->willReturn($job);
        $jobs->expects(self::exactly(2))->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(fn ($message) => new Envelope($message));
        $handler = $this->handler($store, $jobs, $bus);
        try {
            $handler(new ExecuteScheduledOccurrenceCommand($occurrence->id));
            self::fail('Expected rejected return receipt.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('receipt', $error->getMessage());
        }
        $handler(new ExecuteScheduledOccurrenceCommand($occurrence->id));
    }

    /** @return iterable<string, array{string}> */
    public static function cancellations(): iterable
    {
        foreach (['deleted', 'paused', 'disabled', 'type', 'command', 'parameters', 'parameter_type', 'parameter_order', 'zero_sign'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('cancellations')]
    public function testCurrentScheduleIsRecheckedBeforeInvocation(string $change): void
    {
        $job = $this->job();
        if ($change === 'parameter_order') {
            $job->getState()->parameters = ['value' => 1.0, 'other' => 2];
        }
        if ($change === 'zero_sign') {
            $job->getState()->parameters = ['value' => -0.0];
        }
        $occurrence = $this->occurrence($job);
        match ($change) {
            'paused' => $job->pause(),
            'disabled' => $job->disable(),
            'type' => $job->getState()->jobType = JobType::Console,
            'command' => $job->getState()->command = 'app:different',
            'parameters' => $job->getState()->parameters = ['value' => 2.0],
            'parameter_type' => $job->getState()->parameters = ['value' => 1],
            'parameter_order' => $job->getState()->parameters = ['other' => 2, 'value' => 1.0],
            'zero_sign' => $job->getState()->parameters = ['value' => 0.0],
            default => null,
        };
        $store = $this->createMock(SchedulerOccurrenceExecutionStoreInterface::class);
        $store->method('begin')->willReturn($occurrence);
        $store->expects(self::once())->method('markReturned')->willReturn(true);
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->expects(self::once())->method('getById')->willReturn($change === 'deleted' ? null : $job);
        $jobs->expects(self::never())->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        ($this->handler($store, $jobs, $bus))(new ExecuteScheduledOccurrenceCommand($occurrence->id));
        self::assertSame(0, $job->getRunCount());
    }

    public function testRegistryRemovalRecordsFailureWithoutLegacyLockRelease(): void
    {
        $job = $this->job();
        $occurrence = $this->occurrence($job);
        $store = $this->createMock(SchedulerOccurrenceExecutionStoreInterface::class);
        $store->method('begin')->willReturn($occurrence);
        $store->expects(self::once())->method('markReturned')->willReturn(true);
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->method('getById')->willReturn($job);
        $jobs->expects(self::once())->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        ($this->handler($store, $jobs, $bus, new SchedulerRegistry([], [])))(new ExecuteScheduledOccurrenceCommand($occurrence->id));
        self::assertStringContainsString('not registered', $job->getLastError());
    }

    public function testKnownExecutionFailureGetsReturnReceiptWithoutClaimingSuccess(): void
    {
        $job = $this->job();
        $occurrence = $this->occurrence($job);
        $store = $this->createMock(SchedulerOccurrenceExecutionStoreInterface::class);
        $store->method('begin')->willReturn($occurrence);
        $store->expects(self::once())->method('markReturned')->willReturn(true);
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->method('getById')->willReturn($job);
        $jobs->expects(self::exactly(2))->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('Known dispatch failure'));
        ($this->handler($store, $jobs, $bus))(new ExecuteScheduledOccurrenceCommand($occurrence->id));
        self::assertSame('Known dispatch failure', $job->getLastError());
        self::assertNull($job->getLastResult());
    }

    /** @return iterable<string, array{bool}> */
    public static function consoleResults(): iterable
    {
        yield 'success' => [false];
        yield 'completion unknown' => [true];
    }

    #[DataProvider('consoleResults')]
    public function testConsoleOccurrencePreservesSnapshotAndNeverReleasesLegacyLock(bool $timeout): void
    {
        $console = new class extends Command implements SchedulableConsoleCommandInterface {
            public function __construct() { parent::__construct('app:occurrence-test'); }
            public static function schedulerParameters(): array { return []; }
        };
        $parameters = ['integral' => 1.0, 'negative_zero' => -0.0];
        $job = ScheduledJob::create('Console occurrence', '* * * * *', JobType::Console, 'app:occurrence-test', parameters: $parameters);
        $occurrence = $this->occurrence($job);
        $store = $this->createMock(SchedulerOccurrenceExecutionStoreInterface::class);
        $store->method('begin')->willReturn($occurrence);
        $store->expects(self::once())->method('markReturned')->willReturn(true);
        $jobs = $this->createMock(ScheduledJobPortInterface::class);
        $jobs->expects(self::once())->method('getById')->willReturn($job);
        $jobs->expects(self::exactly(2))->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $pool = $this->createMock(CpuProcessPoolInterface::class);
        $pool->expects(self::once())->method('dispatch')->willReturnCallback(function (string $payload, string $key) use ($parameters, $job): void {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($parameters, $decoded['parameters']);
            self::assertStringContainsString('"negative_zero":-0.0', $payload);
            self::assertStringStartsWith('scheduled_console:' . $job->getId()->toString() . ':', $key);
        });
        $pool->expects(self::atLeastOnce())->method('readResult')->willReturn($timeout ? null : ['status' => 'ok', 'data' => '{"success":true,"output":"completed"}']);
        $redis = $this->createMock(RedisClientFactory::class);
        $redis->expects(self::never())->method('borrow');
        $handler = new ExecuteScheduledOccurrenceHandler($store, new ExecuteScheduledJobHandler(
            $jobs, new SchedulerRegistry([], [$console]), $bus, $pool, $redis, new NullLogger(), consoleResultTimeoutSeconds: 0.001,
        ));
        $handler(new ExecuteScheduledOccurrenceCommand($occurrence->id));
        if ($timeout) {
            self::assertSame(ScheduleStatus::Paused, $job->getStatus());
            self::assertStringContainsString('completion unknown', $job->getLastError());
        } else {
            self::assertSame('completed', $job->getLastResult());
        }
    }
}

final readonly class OccurrenceTestMessage implements SchedulableCommandInterface
{
    public function __construct(public float $value = 1.0) {}
    public static function schedulerDescription(): string { return 'Occurrence regression'; }
    public static function schedulerParameters(): array { return []; }
}
