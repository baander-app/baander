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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExecuteScheduledJobHandlerTest extends TestCase
{
    private ScheduledJobPortInterface&MockObject $jobService;
    private MessageBusInterface&MockObject $messageBus;
    private CpuProcessPoolInterface&MockObject $cpuPool;
    private RedisClientFactory&MockObject $redis;
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->jobService = $this->createMock(ScheduledJobPortInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->cpuPool = $this->createMock(CpuProcessPoolInterface::class);
        $this->redis = $this->createMock(RedisClientFactory::class);
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

        // CPU pool dispatch — return null result table (dispatched_to_pool path)
        $this->cpuPool->expects($this->once())->method('dispatch');
        $this->cpuPool->method('getResultTable')->willReturn(null);

        $this->redis->method('borrow');

        ($this->createHandler($registry))($command);

        $this->assertSame(1, $job->getRunCount());
        $this->assertSame('dispatched_to_pool', $job->getLastResult());
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
