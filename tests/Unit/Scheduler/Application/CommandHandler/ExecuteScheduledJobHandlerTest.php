<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Application\CommandHandler;

use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Exception\ScheduledConsoleCompletionUnknown;
use App\Scheduler\Application\Port\ScheduledConsoleExecutorInterface;
use App\Scheduler\Application\CommandHandler\ExecuteScheduledJobHandler;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExecuteScheduledJobHandlerTest extends TestCase
{
    private ScheduledJobPortInterface&Stub $jobService;
    private MessageBusInterface&Stub $messageBus;
    private CpuProcessPoolInterface&Stub $cpuPool;
    private RedisClientFactory&Stub $redis;
    private TestLogger $logger;
    private ScheduledConsoleExecutorInterface&Stub $consoleExecutor;

    protected function setUp(): void
    {
        $this->jobService = $this->createStub(ScheduledJobPortInterface::class);
        $this->messageBus = $this->createStub(MessageBusInterface::class);
        $this->cpuPool = $this->createStub(CpuProcessPoolInterface::class);
        $this->redis = $this->createStub(RedisClientFactory::class);
        $this->logger = new TestLogger();
        $this->consoleExecutor = $this->createStub(ScheduledConsoleExecutorInterface::class);
    }

    private function createHandler(?SchedulerRegistry $registry = null): ExecuteScheduledJobHandler
    {
        $messengerCommand = new TestMessengerCommand();
        $effectiveRegistry = $registry ?? new SchedulerRegistry([$messengerCommand], []);

        return new ExecuteScheduledJobHandler(
            $this->jobService,
            $effectiveRegistry,
            $this->messageBus,
            $this->cpuPool,
            $this->redis,
            $this->logger,
            $this->consoleExecutor,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function occurrenceConsoleOutcomes(): iterable
    {
        foreach (['success', 'known failure', 'unknown', 'unknown with save failure'] as $outcome) {
            yield $outcome => [$outcome];
        }
    }

    #[DataProvider('occurrenceConsoleOutcomes')]
    public function testOccurrenceConsoleUsesIndependentExecutorAndPreservesUncertainty(string $outcome): void
    {
        $console = new class extends Command implements SchedulableConsoleCommandInterface {
            public function __construct() { parent::__construct('app:occurrence-console'); }
            public static function schedulerParameters(): array { return []; }
        };
        $parameters = ['ttl-hours' => 1.0];
        $job = ScheduledJob::create('Occurrence console', '* * * * *', JobType::Console, 'app:occurrence-console', parameters: $parameters);
        $occurrence = new SchedulerOccurrence(Uuid::generate(), $job->getId(), new \DateTimeImmutable('2026-10-02T10:00:00Z'), JobType::Console, $job->getCommand(), $parameters);
        $this->jobService = $this->createMock(ScheduledJobPortInterface::class);
        $this->jobService->expects(self::once())->method('getById')->willReturn($job);
        $persistenceError = new \RuntimeException('Storage failure');
        $saves = 0;
        $this->jobService->expects(self::exactly(2))->method('save')->willReturnCallback(function () use (&$saves, $outcome, $persistenceError): void {
            if (++$saves === 2 && $outcome === 'unknown with save failure') {
                throw $persistenceError;
            }
        });
        $this->cpuPool = $this->createMock(CpuProcessPoolInterface::class);
        $this->cpuPool->expects(self::never())->method('dispatch');
        $this->cpuPool->expects(self::never())->method('readResult');
        $this->redis = $this->createMock(RedisClientFactory::class);
        $this->redis->expects(self::never())->method('borrow');
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->consoleExecutor = $this->createMock(ScheduledConsoleExecutorInterface::class);
        $call = $this->consoleExecutor->expects(self::once())->method('execute')->with('app:occurrence-console', $parameters);
        $unknown = new ScheduledConsoleCompletionUnknown('Console completion unknown: containment required.');
        if ($outcome === 'success') {
            $call->willReturn('completed');
        } elseif ($outcome === 'known failure') {
            $call->willThrowException(new \RuntimeException('Known console failure'));
        } else {
            $call->willThrowException($unknown);
        }
        $handler = $this->createHandler(new SchedulerRegistry([], [$console]));
        if (str_starts_with($outcome, 'unknown')) {
            try {
                $handler->executeOccurrence($occurrence);
                self::fail('Unknown completion must propagate.');
            } catch (ScheduledConsoleCompletionUnknown $actual) {
                if ($outcome === 'unknown') {
                    self::assertSame($unknown, $actual);
                } else {
                    self::assertSame($persistenceError, $actual->getPrevious());
                    self::assertStringContainsString('failed to persist paused schedule', $actual->getMessage());
                }
            }
            self::assertSame(ScheduleStatus::Paused, $job->getStatus());
            self::assertSame($unknown->getMessage(), $job->getLastError());
        } else {
            $handler->executeOccurrence($occurrence);
            self::assertSame(ScheduleStatus::Active, $job->getStatus());
            self::assertSame($outcome === 'success' ? 'completed' : null, $job->getLastResult());
            self::assertSame($outcome === 'known failure' ? 'Known console failure' : null, $job->getLastError());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function occurrenceConsoleRejections(): iterable
    {
        foreach (['snapshot changed', 'paused', 'not registered'] as $reason) {
            yield $reason => [$reason];
        }
    }

    #[DataProvider('occurrenceConsoleRejections')]
    public function testOccurrenceConsoleAdmissionRejectsBeforeExecutor(string $reason): void
    {
        $job = ScheduledJob::create('Console gate', '* * * * *', JobType::Console, 'app:occurrence-console', parameters: ['value' => 1.0]);
        if ($reason === 'paused') {
            $job->pause();
        }
        $occurrence = new SchedulerOccurrence(Uuid::generate(), $job->getId(), new \DateTimeImmutable('2026-10-02T10:00:00Z'), JobType::Console, $job->getCommand(), ['value' => $reason === 'snapshot changed' ? 2.0 : 1.0]);
        $this->jobService = $this->createMock(ScheduledJobPortInterface::class);
        $this->jobService->expects(self::once())->method('getById')->willReturn($job);
        $this->jobService->expects($reason === 'not registered' ? self::once() : self::never())->method('save');
        $this->consoleExecutor = $this->createMock(ScheduledConsoleExecutorInterface::class);
        $this->consoleExecutor->expects(self::never())->method('execute');
        $this->cpuPool = $this->createMock(CpuProcessPoolInterface::class);
        $this->cpuPool->expects(self::never())->method('dispatch');
        $this->redis = $this->createMock(RedisClientFactory::class);
        $this->redis->expects(self::never())->method('borrow');
        $this->createHandler(new SchedulerRegistry([], []))->executeOccurrence($occurrence);
    }

    // --- Job not found ---

    public function testInvocationSkipsWhenJobNotFound(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $command = new ExecuteScheduledJobCommand(
            jobId: Uuid::v4()->toString(),
            jobType: JobType::Messenger->value,
            command: TestMessengerCommand::class,
            parameters: [],
        );

        $this->jobService->method('getById')->willReturn(null);
        $this->messageBus->expects($this->never())->method('dispatch');

        ($this->createHandler())($command);

        $this->assertTrue($this->logger->hasWarningContaining('not found'));
    }

    // --- Command not in registry ---

    public function testInvocationFailsWhenMessengerCommandNotInRegistry(): void
    {
        $this->jobService = $this->createMock(ScheduledJobPortInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $job = ScheduledJob::create(
            name: 'Rogue',
            expression: '* * * * *',
            jobType: JobType::Messenger,
            command: 'App\UnregisteredCommand',
        );

        $command = new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: JobType::Messenger->value,
            command: 'App\UnregisteredCommand',
            parameters: [],
        );

        $this->jobService->method('getById')->willReturn($job);
        // markFailed save + final save
        $this->jobService->expects($this->once())->method('save');
        $this->messageBus->expects($this->never())->method('dispatch');
        $this->redis->method('borrow');

        ($this->createHandler())($command);

        $this->assertStringContainsString('not registered', $job->getLastError());
        $this->assertSame(1, $job->getRunCount());
    }

    // --- Successful messenger dispatch ---

    public function testSuccessfulMessengerDispatch(): void
    {
        $this->jobService = $this->createMock(ScheduledJobPortInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $job = ScheduledJob::create(
            name: 'Valid Messenger',
            expression: '* * * * *',
            jobType: JobType::Messenger,
            command: TestMessengerCommand::class,
            parameters: ['message' => 'hello'],
        );

        $command = new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: JobType::Messenger->value,
            command: TestMessengerCommand::class,
            parameters: ['message' => 'hello'],
        );

        $this->jobService->method('getById')->willReturn($job);
        // markRunning save + final save = 2
        $this->jobService->expects($this->exactly(2))->method('save');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TestMessengerCommand::class))
            ->willReturnCallback(fn ($msg) => new Envelope($msg));

        $this->redis->method('borrow');

        ($this->createHandler())($command);

        $this->assertSame(1, $job->getRunCount());
        $this->assertSame('dispatched', $job->getLastResult());
        $this->assertNull($job->getLastError());
    }

    // --- Messenger dispatch with missing class ---

    public function testMessengerDispatchFailsWhenClassDoesNotExist(): void
    {
        $registry = new class([], []) extends SchedulerRegistry {
            public function isMessengerCommandAllowed(string $fqcn): bool { return true; }
        };

        $job = ScheduledJob::create(
            name: 'Missing Class',
            expression: '* * * * *',
            jobType: JobType::Messenger,
            command: 'App\Completely\Nonexistent\Class',
        );

        $command = new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: JobType::Messenger->value,
            command: 'App\Completely\Nonexistent\Class',
            parameters: [],
        );

        $this->jobService->method('getById')->willReturn($job);
        $this->redis->method('borrow');

        ($this->createHandler($registry))($command);

        $this->assertSame(1, $job->getRunCount());
        $this->assertNotNull($job->getLastError());
        $this->assertStringContainsString('does not exist', $job->getLastError());
    }

    // --- Console dispatch ---

    public function testConsoleDispatchUsesCpuPool(): void
    {
        $this->cpuPool = $this->createMock(CpuProcessPoolInterface::class);

        $consoleCommand = new class extends Command implements SchedulableConsoleCommandInterface {
            public function __construct()
            {
                parent::__construct('app:test-console-cmd');
            }

            public static function schedulerParameters(): array { return []; }
        };

        $registry = new SchedulerRegistry([], [$consoleCommand]);

        $job = ScheduledJob::create(
            name: 'Console Job',
            expression: '* * * * *',
            jobType: JobType::Console,
            command: 'app:test-console-cmd',
            parameters: ['--limit' => '10'],
        );

        $command = new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: JobType::Console->value,
            command: 'app:test-console-cmd',
            parameters: ['--limit' => '10'],
        );

        $this->jobService->method('getById')->willReturn($job);

        // Results are available through readResult even without a shared-memory table.
        $this->cpuPool->expects($this->once())->method('dispatch');
        $this->cpuPool->method('getResultTable')->willReturn(null);
        $this->cpuPool->expects($this->once())->method('readResult')->willReturn([
            'status' => 'ok',
            'data' => json_encode(['success' => true, 'output' => 'completed console output'], JSON_THROW_ON_ERROR),
        ]);

        $this->redis->method('borrow');

        ($this->createHandler($registry))($command);

        $this->assertSame(1, $job->getRunCount());
        $this->assertSame('completed console output', $job->getLastResult());
    }

    public function testOuterPoolErrorMarksFailureWithItsDiagnosticWithoutPausing(): void
    {
        [$handler, $job, $command, $pool] = $this->prepareConsoleAttempt();
        $pool->expects($this->once())->method('readResult')->willReturn([
            'status' => 'error', 'data' => 'Worker process could not load console handler',
        ]);

        $handler($command);

        self::assertStringContainsString('Worker process could not load console handler', $job->getLastError());
        self::assertNull($job->getLastResult());
        self::assertSame(ScheduleStatus::Active, $job->getStatus());
        self::assertSame(1, $job->getRunCount());
    }

    public function testUnsuccessfulConsoleResultMarksFailureWithoutPausing(): void
    {
        [$handler, $job, $command, $pool] = $this->prepareConsoleAttempt();
        $pool->expects($this->once())->method('readResult')->willReturn([
            'status' => 'ok',
            'data' => json_encode(['success' => false, 'error' => 'Console exited with code 9', 'exitCode' => 9], JSON_THROW_ON_ERROR),
        ]);

        $handler($command);

        self::assertStringContainsString('Console exited with code 9', $job->getLastError());
        self::assertNull($job->getLastResult());
        self::assertSame(ScheduleStatus::Active, $job->getStatus());
        self::assertSame(1, $job->getRunCount());
    }

    /** @param array{status: string, data: string} $row */
    #[DataProvider('malformedConsoleResults')]
    public function testConsumedMalformedConsoleResultFailsWithoutPausing(array $row): void
    {
        [$handler, $job, $command, $pool] = $this->prepareConsoleAttempt();
        $pool->expects($this->once())->method('readResult')->willReturn($row);

        $handler($command);

        self::assertNotNull($job->getLastError());
        self::assertNotSame('', $job->getLastError());
        self::assertNull($job->getLastResult());
        self::assertSame(ScheduleStatus::Active, $job->getStatus());
        self::assertSame(1, $job->getRunCount());
    }

    /** @return iterable<string, array{array{status: string, data: string}}> */
    public static function malformedConsoleResults(): iterable
    {
        yield 'numeric success' => [['status' => 'ok', 'data' => '{"success":1,"output":"not valid"}']];
        yield 'string success' => [['status' => 'ok', 'data' => '{"success":"true","output":"not valid"}']];
        yield 'missing success' => [['status' => 'ok', 'data' => '{"output":"not valid"}']];
        yield 'scalar JSON' => [['status' => 'ok', 'data' => 'true']];
        yield 'malformed JSON' => [['status' => 'ok', 'data' => '{broken']];
        yield 'null output' => [['status' => 'ok', 'data' => '{"success":true,"output":null}']];
        yield 'non-string output' => [['status' => 'ok', 'data' => '{"success":true,"output":123}']];
        yield 'unknown pool status' => [['status' => 'unexpected', 'data' => '{"success":true,"output":"not valid"}']];
    }

    #[DataProvider('uncertainCompletionStates')]
    public function testUnknownCompletionRecordsFailureAndOnlyPausesAnActiveSchedule(
        ScheduleStatus $initialStatus,
        bool $resultReadFails,
    ): void {
        [$handler, $job, $command, $pool] = $this->prepareConsoleAttempt();
        if ($initialStatus === ScheduleStatus::Paused) {
            $job->pause();
        } elseif ($initialStatus === ScheduleStatus::Disabled) {
            $job->disable();
        }
        if ($resultReadFails) {
            $pool->expects($this->once())->method('readResult')->willThrowException(new \RuntimeException('Result store unavailable'));
        } else {
            $pool->expects($this->atLeastOnce())->method('readResult')->willReturn(null);
        }

        $handler($command);

        self::assertSame($initialStatus === ScheduleStatus::Active ? ScheduleStatus::Paused : $initialStatus, $job->getStatus());
        self::assertFalse($job->isDue(new \DateTimeImmutable('+1 day')), 'Unknown completion must prevent the next cron dispatch.');
        self::assertNotNull($job->getLastError());
        self::assertStringContainsString('completion unknown', strtolower($job->getLastError()));
        self::assertNull($job->getLastResult());
        self::assertNotNull($job->getLastFailureAt());
        self::assertSame(1, $job->getRunCount());
    }

    /** @return iterable<string, array{ScheduleStatus, bool}> */
    public static function uncertainCompletionStates(): iterable
    {
        foreach (ScheduleStatus::cases() as $status) {
            yield $status->value . ' timeout' => [$status, false];
            yield $status->value . ' result read failure' => [$status, true];
        }
    }

    public function testUnknownCompletionDoesNotRetryWhenLockReleaseAndItsWarningFail(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $logger->expects($this->once())->method('warning')->willThrowException(new \RuntimeException('Logger unavailable'));
        $savedStates = [];
        [$handler, $job, $command, $pool] = $this->prepareConsoleAttempt(
            logger: $logger,
            onSave: static function (ScheduledJob $saved) use (&$savedStates): void {
                $savedStates[] = ['status' => $saved->getStatus(), 'error' => $saved->getLastError()];
            },
        );
        $pool->expects($this->atLeastOnce())->method('readResult')->willReturn(null);
        $this->redis->method('borrow')->willThrowException(new \RuntimeException('Redis unavailable'));

        $handler($command);

        self::assertSame(ScheduleStatus::Paused, $job->getStatus());
        self::assertStringContainsString('completion unknown', strtolower($job->getLastError()));
        self::assertNull($job->getLastResult());
        self::assertSame(1, $job->getRunCount());
        self::assertSame(ScheduleStatus::Active, $savedStates[0]['status']);
        self::assertNull($savedStates[0]['error']);
        self::assertSame(ScheduleStatus::Paused, $savedStates[1]['status']);
        self::assertSame($job->getLastError(), $savedStates[1]['error']);
    }

    public function testRepeatedConsoleAttemptsUseDifferentResultKeysAndPreserveSuccessfulOutput(): void
    {
        [$handler, $job, $command, $pool] = $this->prepareConsoleAttempt(attempts: 2);
        $keys = [];
        $pool->expects($this->exactly(2))->method('readResult')->willReturnCallback(
            static function (string $key) use (&$keys): array {
                $keys[] = $key;
                return ['status' => 'ok', 'data' => '{"success":true,"output":"completed result"}'];
            },
        );

        $handler($command);
        $handler($command);

        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
        self::assertSame('completed result', $job->getLastResult());
        self::assertNull($job->getLastError());
        self::assertSame(2, $job->getRunCount());
        self::assertSame(ScheduleStatus::Active, $job->getStatus());
    }

    #[DataProvider('invalidConsoleTimeouts')]
    public function testConsoleResultTimeoutRejectsNonPositiveOrNonFiniteValues(float $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExecuteScheduledJobHandler(
            $this->jobService,
            new SchedulerRegistry([], []),
            $this->messageBus,
            $this->cpuPool,
            $this->redis,
            $this->logger,
            consoleResultTimeoutSeconds: $timeout,
            consoleExecutor: $this->consoleExecutor,
        );
    }

    /** @return iterable<string, array{float}> */
    public static function invalidConsoleTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.1];
        yield 'infinity' => [INF];
        yield 'not a number' => [NAN];
    }

    /** @return array{ExecuteScheduledJobHandler, ScheduledJob, ExecuteScheduledJobCommand, CpuProcessPoolInterface&MockObject} */
    private function prepareConsoleAttempt(int $attempts = 1, float $timeout = 0.001, ?LoggerInterface $logger = null, ?\Closure $onSave = null): array
    {
        $consoleCommand = new class extends Command implements SchedulableConsoleCommandInterface {
            public function __construct()
            {
                parent::__construct('app:test-console-cmd');
            }

            public static function schedulerParameters(): array { return []; }
        };
        $job = ScheduledJob::create(
            name: 'Console result test',
            expression: '* * * * *',
            jobType: JobType::Console,
            command: 'app:test-console-cmd',
        );
        $command = new ExecuteScheduledJobCommand($job->getId()->toString(), JobType::Console->value, 'app:test-console-cmd', []);
        $pool = $this->createMock(CpuProcessPoolInterface::class);
        $pool->method('getResultTable')->willReturn(null);
        $this->jobService = $this->createMock(ScheduledJobPortInterface::class);
        $this->jobService->method('getById')->willReturn($job);
        $this->redis = $this->createMock(RedisClientFactory::class);
        $handler = new ExecuteScheduledJobHandler(
            $this->jobService,
            new SchedulerRegistry([], [$consoleCommand]),
            $this->messageBus,
            $pool,
            $this->redis,
            $logger ?? $this->logger,
            consoleResultTimeoutSeconds: $timeout,
            consoleExecutor: $this->consoleExecutor,
        );
        $pool->expects($this->exactly($attempts))->method('dispatch');
        $this->jobService->expects($this->exactly(2 * $attempts))->method('save')->willReturnCallback(
            $onSave ?? static function (ScheduledJob $saved): void {},
        );
        $this->redis->expects($this->exactly($attempts))->method('borrow');

        return [$handler, $job, $command, $pool];
    }

    // --- Console dispatch rejected when not in registry ---

    public function testConsoleDispatchRejectedWhenNotInRegistry(): void
    {
        $job = ScheduledJob::create(
            name: 'Console Job',
            expression: '* * * * *',
            jobType: JobType::Console,
            command: 'app:unknown-cmd',
            parameters: [],
        );

        $command = new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: JobType::Console->value,
            command: 'app:unknown-cmd',
            parameters: [],
        );

        $this->jobService->method('getById')->willReturn($job);
        $this->redis->method('borrow');

        ($this->createHandler())($command);

        $this->assertStringContainsString('not registered', $job->getLastError());
    }

    // --- Unknown job type ---

    public function testUnknownJobTypeIsRejected(): void
    {
        $job = ScheduledJob::create(
            name: 'Unknown Type',
            expression: '* * * * *',
            jobType: JobType::Messenger,
            command: TestMessengerCommand::class,
        );

        $command = new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: 'unknown_type',
            command: TestMessengerCommand::class,
            parameters: [],
        );

        $this->jobService->method('getById')->willReturn($job);
        $this->redis->method('borrow');

        ($this->createHandler())($command);

        $this->assertStringContainsString('not registered', $job->getLastError());
    }

    // --- Lock release on failure ---

    public function testJobMarkedFailedOnMessengerException(): void
    {
        $this->redis = $this->createMock(RedisClientFactory::class);

        $job = ScheduledJob::create(
            name: 'Failing',
            expression: '* * * * *',
            jobType: JobType::Messenger,
            command: TestMessengerCommand::class,
            parameters: [],
        );

        $command = new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: JobType::Messenger->value,
            command: TestMessengerCommand::class,
            parameters: [],
        );

        $this->jobService->method('getById')->willReturn($job);

        $this->messageBus->method('dispatch')
            ->willThrowException(new \RuntimeException('Bus error'));

        // Redis borrow called for lock release
        $this->redis->expects($this->once())->method('borrow');

        ($this->createHandler())($command);

        $this->assertSame('Bus error', $job->getLastError());
        $this->assertSame(1, $job->getRunCount());
    }

    // --- markRunning is called before dispatch ---

    public function testMarkRunningCalledBeforeExecution(): void
    {
        $job = ScheduledJob::create(
            name: 'Timing',
            expression: '* * * * *',
            jobType: JobType::Messenger,
            command: TestMessengerCommand::class,
        );
        $this->assertNull($job->getLastRunAt());

        $command = new ExecuteScheduledJobCommand(
            jobId: $job->getId()->toString(),
            jobType: JobType::Messenger->value,
            command: TestMessengerCommand::class,
            parameters: [],
        );

        $this->jobService->method('getById')->willReturn($job);
        $this->messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        $this->redis->method('borrow');

        ($this->createHandler())($command);

        $this->assertNotNull($job->getLastRunAt());
    }
}

// --- Test fixtures ---

final class TestMessengerCommand implements SchedulableCommandInterface
{
    public function __construct(public readonly string $message = 'default')
    {
    }

    public static function schedulerDescription(): string
    {
        return 'Test messenger command for unit tests';
    }

    public static function schedulerParameters(): array
    {
        return [
            'message' => ['type' => 'string', 'required' => false, 'default' => 'default'],
        ];
    }
}

final class TestLogger extends AbstractLogger
{
    /** @var array<string, string[]> */
    private array $messages = [];

    /** @param mixed[] $context */
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[(string) $level][] = (string) $message;
    }

    public function hasWarningContaining(string $needle): bool
    {
        foreach ($this->messages['warning'] ?? [] as $msg) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }

    public function hasErrorContaining(string $needle): bool
    {
        foreach ($this->messages['error'] ?? [] as $msg) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }
}
