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
    private TestLogger $logger;
    private ScheduledConsoleExecutorInterface&Stub $consoleExecutor;

    protected function setUp(): void
    {
        $this->jobService = $this->createStub(ScheduledJobPortInterface::class);
        $this->messageBus = $this->createStub(MessageBusInterface::class);
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
        $this->createHandler(new SchedulerRegistry([], []))->executeOccurrence($occurrence);
    }

    public function testLegacyWrapperIsRejectedBeforeAnyJobReadOrEffect(): void
    {
        $this->jobService = $this->createMock(ScheduledJobPortInterface::class);
        $this->jobService->expects(self::never())->method('getById');
        $this->jobService->expects(self::never())->method('save');
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->consoleExecutor = $this->createMock(ScheduledConsoleExecutorInterface::class);
        $this->consoleExecutor->expects(self::never())->method('execute');
        $this->expectException(\Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException::class);
        ($this->createHandler())(new ExecuteScheduledJobCommand(Uuid::generate()->toString(), JobType::Messenger->value, TestMessengerCommand::class, []));
    }

    private function executeOccurrence(ExecuteScheduledJobHandler $handler, ExecuteScheduledJobCommand $command): void
    {
        $handler->executeOccurrence(new SchedulerOccurrence(
            Uuid::generate(), Uuid::fromString($command->jobId), new \DateTimeImmutable('2026-10-03T10:00:00Z'),
            JobType::from($command->jobType), $command->command, $command->parameters,
        ));
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

        $this->executeOccurrence($this->createHandler(), $command);

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

        $this->executeOccurrence($this->createHandler(), $command);

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


        $this->executeOccurrence($this->createHandler(), $command);

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

        $this->executeOccurrence($this->createHandler($registry), $command);

        $this->assertSame(1, $job->getRunCount());
        $this->assertNotNull($job->getLastError());
        $this->assertStringContainsString('does not exist', $job->getLastError());
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

        $this->executeOccurrence($this->createHandler(), $command);

        $this->assertStringContainsString('not registered', $job->getLastError());
    }

    // --- Messenger failure ---

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


        $this->executeOccurrence($this->createHandler(), $command);

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

        $this->executeOccurrence($this->createHandler(), $command);

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
