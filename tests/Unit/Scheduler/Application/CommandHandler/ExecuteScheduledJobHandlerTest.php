<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Application\CommandHandler;

use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
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
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExecuteScheduledJobHandlerTest extends TestCase
{
    private ScheduledJobPortInterface $jobService;
    private MessageBusInterface $messageBus;
    private CpuProcessPoolInterface $cpuPool;
    private RedisClientFactory $redis;
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->jobService = $this->createStub(ScheduledJobPortInterface::class);
        $this->messageBus = $this->createStub(MessageBusInterface::class);
        $this->cpuPool = $this->createStub(CpuProcessPoolInterface::class);
        $this->redis = $this->createStub(RedisClientFactory::class);
        $this->logger = new TestLogger();
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
        );
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
        [$handler, $job, $command] = $this->prepareConsoleAttempt();
        $this->cpuPool->expects($this->once())->method('readResult')->willReturn([
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
        [$handler, $job, $command] = $this->prepareConsoleAttempt();
        $this->cpuPool->expects($this->once())->method('readResult')->willReturn([
            'status' => 'ok',
            'data' => json_encode(['success' => false, 'error' => 'Console exited with code 9', 'exitCode' => 9], JSON_THROW_ON_ERROR),
        ]);

        $handler($command);

        self::assertStringContainsString('Console exited with code 9', $job->getLastError());
        self::assertNull($job->getLastResult());
        self::assertSame(ScheduleStatus::Active, $job->getStatus());
        self::assertSame(1, $job->getRunCount());
    }

    #[DataProvider('malformedConsoleResults')]
    public function testConsumedMalformedConsoleResultFailsWithoutPausing(array $row): void
    {
        [$handler, $job, $command] = $this->prepareConsoleAttempt();
        $this->cpuPool->expects($this->once())->method('readResult')->willReturn($row);

        $handler($command);

        self::assertNotNull($job->getLastError());
        self::assertNotSame('', $job->getLastError());
        self::assertNull($job->getLastResult());
        self::assertSame(ScheduleStatus::Active, $job->getStatus());
        self::assertSame(1, $job->getRunCount());
    }

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
        [$handler, $job, $command] = $this->prepareConsoleAttempt();
        if ($initialStatus === ScheduleStatus::Paused) {
            $job->pause();
        } elseif ($initialStatus === ScheduleStatus::Disabled) {
            $job->disable();
        }
        if ($resultReadFails) {
            $this->cpuPool->expects($this->once())->method('readResult')->willThrowException(new \RuntimeException('Result store unavailable'));
        } else {
            $this->cpuPool->expects($this->atLeastOnce())->method('readResult')->willReturn(null);
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
        [$handler, $job, $command] = $this->prepareConsoleAttempt(
            logger: $logger,
            onSave: static function (ScheduledJob $saved) use (&$savedStates): void {
                $savedStates[] = ['status' => $saved->getStatus(), 'error' => $saved->getLastError()];
            },
        );
        $this->cpuPool->expects($this->atLeastOnce())->method('readResult')->willReturn(null);
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
        [$handler, $job, $command] = $this->prepareConsoleAttempt(attempts: 2);
        $keys = [];
        $this->cpuPool->expects($this->exactly(2))->method('readResult')->willReturnCallback(
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
        );
    }

    public static function invalidConsoleTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.1];
        yield 'infinity' => [INF];
        yield 'not a number' => [NAN];
    }

    /** @return array{ExecuteScheduledJobHandler, ScheduledJob, ExecuteScheduledJobCommand} */
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
        $this->cpuPool = $this->createMock(CpuProcessPoolInterface::class);
        $this->cpuPool->method('getResultTable')->willReturn(null);
        $this->jobService = $this->createMock(ScheduledJobPortInterface::class);
        $this->jobService->method('getById')->willReturn($job);
        $this->redis = $this->createMock(RedisClientFactory::class);
        $handler = new ExecuteScheduledJobHandler(
            $this->jobService,
            new SchedulerRegistry([], [$consoleCommand]),
            $this->messageBus,
            $this->cpuPool,
            $this->redis,
            $logger ?? $this->logger,
            consoleResultTimeoutSeconds: $timeout,
        );
        $this->cpuPool->expects($this->exactly($attempts))->method('dispatch');
        $this->jobService->expects($this->exactly(2 * $attempts))->method('save')->willReturnCallback(
            $onSave ?? static function (ScheduledJob $saved): void {},
        );
        $this->redis->expects($this->exactly($attempts))->method('borrow');

        return [$handler, $job, $command];
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
