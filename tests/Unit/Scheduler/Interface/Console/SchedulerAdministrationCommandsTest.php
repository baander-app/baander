<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Interface\Console;

use App\Scheduler\Domain\Exception\ScheduledJobConflict as PersistenceConflict;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Scheduler\Infrastructure\Service\ScheduledJobService;
use App\Scheduler\Interface\Console\ScheduledJobInputReader;
use App\Scheduler\Interface\Console\SchedulerCommandsCommand;
use App\Scheduler\Interface\Console\SchedulerCreateCommand;
use App\Scheduler\Interface\Console\SchedulerDeleteCommand;
use App\Scheduler\Interface\Console\SchedulerDisableCommand;
use App\Scheduler\Interface\Console\SchedulerEnableCommand;
use App\Scheduler\Interface\Console\SchedulerListCommand;
use App\Scheduler\Interface\Console\SchedulerPauseCommand;
use App\Scheduler\Interface\Console\SchedulerResumeCommand;
use App\Scheduler\Interface\Console\SchedulerShowCommand;
use App\Scheduler\Interface\Console\SchedulerUpdateCommand;
use App\Scheduler\Interface\Resource\ScheduledJobResource;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The app:scheduler:* commands run the administration port the scheduler page uses. */
final class SchedulerAdministrationCommandsTest extends TestCase
{
    private const array CATALOG = [
        'messenger' => [],
        'console' => [
            'app:test' => [
                'description' => 'A schedulable test command',
                'parameters' => ['limit' => ['type' => 'int', 'required' => true, 'description' => 'How many']],
            ],
        ],
    ];

    /** @var array<string, ScheduledJob> */
    private array $rows = [];
    private bool $conflictOnWrite = false;
    private ScheduledJobService $jobs;

    protected function setUp(): void
    {
        $this->jobs = new ScheduledJobService($this->repository(), $this->registry());
    }

    public function testCreateWithAnInvalidCronExpressionIsInvalidWithTheValidationMessage(): void
    {
        $tester = $this->tester(new SchedulerCreateCommand($this->jobs, $this->reader()));

        $exitCode = $tester->execute([
            '--name' => 'Nightly test',
            '--expression' => 'not a cron',
            '--type' => 'console',
            '--command' => 'app:test',
            '--parameters' => '{"limit": 3}',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Validation failed.', $tester->getErrorOutput());
        self::assertStringContainsString('expression: Invalid cron expression:', $tester->getErrorOutput());
        self::assertSame([], $this->rows);
    }

    public function testCreateStoresTheJobWithItsParameters(): void
    {
        $tester = $this->tester(new SchedulerCreateCommand($this->jobs, $this->reader()));

        $exitCode = $tester->execute([
            '--name' => 'Nightly test',
            '--expression' => '0 3 * * *',
            '--type' => 'console',
            '--command' => 'app:test',
            '--description' => 'Runs the test command',
            '--parameters' => '{"limit": 3}',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertCount(1, $this->rows);
        $job = array_values($this->rows)[0];
        self::assertSame('Nightly test', $job->getName());
        self::assertSame(JobType::Console, $job->getJobType());
        self::assertSame('Runs the test command', $job->getDescription());
        self::assertSame(['limit' => 3], $job->getParameters());
        self::assertStringContainsString($job->getId()->toString(), $tester->getDisplay());
    }

    public function testCreateRejectsParametersOutsideTheCommandsSchema(): void
    {
        $tester = $this->tester(new SchedulerCreateCommand($this->jobs, $this->reader()));

        $exitCode = $tester->execute([
            '--name' => 'Nightly test',
            '--expression' => '0 3 * * *',
            '--type' => 'console',
            '--command' => 'app:test',
            '--parameters' => '{"limit": "3"}',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid parameter "limit": expected int, got string.', $tester->getErrorOutput());
        self::assertSame([], $this->rows);
    }

    #[DataProvider('malformedParameters')]
    public function testParametersMustBeAJsonObject(string $parameters): void
    {
        $tester = $this->tester(new SchedulerCreateCommand($this->jobs, $this->reader()));

        $exitCode = $tester->execute([
            '--name' => 'Nightly test',
            '--expression' => '0 3 * * *',
            '--type' => 'console',
            '--command' => 'app:test',
            '--parameters' => $parameters,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('The --parameters option must be a JSON object.', $tester->getErrorOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedParameters(): iterable
    {
        yield 'not JSON' => ['limit=3'];
        yield 'a list' => ['[3]'];
        yield 'a scalar' => ['3'];
    }

    public function testUpdateKeepsTheFieldsItIsNotGiven(): void
    {
        $job = $this->stored();
        $tester = $this->tester(new SchedulerUpdateCommand($this->jobs, $this->reader()));

        $exitCode = $tester->execute(['id' => $job->getId()->toString(), '--name' => 'Renamed']);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $updated = $this->rows[$job->getId()->toString()];
        self::assertSame('Renamed', $updated->getName());
        self::assertSame($job->getExpression(), $updated->getExpression());
        self::assertSame($job->getCommand(), $updated->getCommand());
        self::assertSame($job->getDescription(), $updated->getDescription());
        self::assertSame($job->getParameters(), $updated->getParameters());
    }

    public function testUpdateClearsTheDescriptionWhenGivenAnEmptyOne(): void
    {
        $job = $this->stored();
        $tester = $this->tester(new SchedulerUpdateCommand($this->jobs, $this->reader()));

        $exitCode = $tester->execute(['id' => $job->getId()->toString(), '--description' => '']);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertNull($this->rows[$job->getId()->toString()]->getDescription());
    }

    public function testUpdateOfAConcurrentlyChangedJobReportsTheConflict(): void
    {
        $job = $this->stored();
        $this->conflictOnWrite = true;
        $tester = $this->tester(new SchedulerUpdateCommand($this->jobs, $this->reader()));

        $exitCode = $tester->execute(
            ['id' => $job->getId()->toString(), '--name' => 'Renamed'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Scheduled job changed. Reload it and try again.', $tester->getErrorOutput());
        self::assertSame('Original', $this->rows[$job->getId()->toString()]->getName());
    }

    public function testPausingAPausedJobSucceeds(): void
    {
        $job = $this->stored();
        $tester = $this->tester(new SchedulerPauseCommand($this->jobs));

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $job->getId()->toString()]));
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $job->getId()->toString()]), $tester->getDisplay());
        self::assertSame(ScheduleStatus::Paused, $this->rows[$job->getId()->toString()]->getStatus());
        self::assertStringContainsString('is paused', $tester->getDisplay());
    }

    public function testPausingADisabledJobFailsWithTheConflict(): void
    {
        $job = $this->stored(ScheduleStatus::Disabled);
        $tester = $this->tester(new SchedulerPauseCommand($this->jobs));

        $exitCode = $tester->execute(['id' => $job->getId()->toString()], ['capture_stderr_separately' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('A disabled job cannot be paused. Enable it first.', $tester->getErrorOutput());
    }

    /**
     * @param \Closure(self): Command $command
     */
    #[DataProvider('lifecycle')]
    public function testLifecycleCommandsMoveTheJob(\Closure $command, ScheduleStatus $from, ScheduleStatus $to): void
    {
        $job = $this->stored($from);
        $tester = $this->tester($command($this));

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $job->getId()->toString()]), $tester->getDisplay());
        self::assertSame($to, $this->rows[$job->getId()->toString()]->getStatus());
    }

    /** @return iterable<string, array{\Closure(self): Command, ScheduleStatus, ScheduleStatus}> */
    public static function lifecycle(): iterable
    {
        $pause = static fn (self $test): Command => new SchedulerPauseCommand($test->jobs);
        $resume = static fn (self $test): Command => new SchedulerResumeCommand($test->jobs);
        $disable = static fn (self $test): Command => new SchedulerDisableCommand($test->jobs);
        $enable = static fn (self $test): Command => new SchedulerEnableCommand($test->jobs);

        yield 'pause' => [$pause, ScheduleStatus::Active, ScheduleStatus::Paused];
        yield 'resume' => [$resume, ScheduleStatus::Paused, ScheduleStatus::Active];
        yield 'disable' => [$disable, ScheduleStatus::Active, ScheduleStatus::Disabled];
        yield 'enable' => [$enable, ScheduleStatus::Disabled, ScheduleStatus::Active];
        yield 'resume an active job' => [$resume, ScheduleStatus::Active, ScheduleStatus::Active];
        yield 'enable an active job' => [$enable, ScheduleStatus::Active, ScheduleStatus::Active];
        yield 'enable a paused job' => [$enable, ScheduleStatus::Paused, ScheduleStatus::Paused];
        yield 'disable a disabled job' => [$disable, ScheduleStatus::Disabled, ScheduleStatus::Disabled];
    }

    /**
     * @param \Closure(self): Command $command
     * @param array<string, mixed> $options
     */
    #[DataProvider('targetedCommands')]
    public function testAnUnknownJobFailsAndAMalformedIdIsInvalid(\Closure $command, array $options): void
    {
        $tester = $this->tester($command($this));

        $missing = $tester->execute(['id' => Uuid::generate()->toString()] + $options, ['capture_stderr_separately' => true]);
        self::assertSame(Command::FAILURE, $missing);
        self::assertStringContainsString('Scheduled job not found.', $tester->getErrorOutput());

        $malformed = $tester->execute(['id' => 'nightly'] + $options, ['capture_stderr_separately' => true]);
        self::assertSame(Command::INVALID, $malformed);
        self::assertStringContainsString('The job ID must be a UUID.', $tester->getErrorOutput());
    }

    /** @return iterable<string, array{\Closure(self): Command, array<string, mixed>}> */
    public static function targetedCommands(): iterable
    {
        yield 'show' => [static fn (self $test): Command => new SchedulerShowCommand($test->jobs), []];
        yield 'update' => [static fn (self $test): Command => new SchedulerUpdateCommand($test->jobs, $test->reader()), ['--name' => 'Renamed']];
        yield 'delete' => [static fn (self $test): Command => new SchedulerDeleteCommand($test->jobs), ['--force' => true]];
        yield 'pause' => [static fn (self $test): Command => new SchedulerPauseCommand($test->jobs), []];
        yield 'resume' => [static fn (self $test): Command => new SchedulerResumeCommand($test->jobs), []];
        yield 'enable' => [static fn (self $test): Command => new SchedulerEnableCommand($test->jobs), []];
        yield 'disable' => [static fn (self $test): Command => new SchedulerDisableCommand($test->jobs), []];
    }

    public function testDeleteWithoutATerminalNeedsForce(): void
    {
        $job = $this->stored();
        $tester = $this->tester(new SchedulerDeleteCommand($this->jobs));

        $refused = $tester->execute(['id' => $job->getId()->toString()], ['interactive' => false, 'capture_stderr_separately' => true]);
        self::assertSame(Command::INVALID, $refused);
        self::assertArrayHasKey($job->getId()->toString(), $this->rows);

        $forced = $tester->execute(['id' => $job->getId()->toString(), '--force' => true], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $forced, $tester->getDisplay());
        self::assertSame([], $this->rows);
    }

    public function testDeleteAsksOnATerminal(): void
    {
        $job = $this->stored();
        $tester = $this->tester(new SchedulerDeleteCommand($this->jobs));

        $tester->setInputs(['no']);
        self::assertSame(Command::FAILURE, $tester->execute(['id' => $job->getId()->toString()]));
        self::assertArrayHasKey($job->getId()->toString(), $this->rows);

        $tester->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $job->getId()->toString()]));
        self::assertSame([], $this->rows);
    }

    public function testCommandsListsTheCatalogTheCreateDialogOffers(): void
    {
        $tester = $this->tester(new SchedulerCommandsCommand($this->jobs));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame(self::CATALOG, json_decode($tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('app:test', $tester->getDisplay());
        self::assertStringContainsString('limit (int, required)', $tester->getDisplay());
    }

    public function testListAndShowPrintTheApiResources(): void
    {
        $job = $this->stored();
        $list = $this->tester(new SchedulerListCommand($this->jobs));
        $show = $this->tester(new SchedulerShowCommand($this->jobs));

        self::assertSame(Command::SUCCESS, $list->execute(['--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame(
            ScheduledJobResource::collection($this->jobs->findAll()),
            json_decode($list->getDisplay(), true, 32, JSON_THROW_ON_ERROR),
        );
        self::assertSame(Command::SUCCESS, $show->execute(['id' => $job->getId()->toString(), '--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame(
            ScheduledJobResource::from($this->jobs->getById($job->getId())),
            json_decode($show->getDisplay(), true, 32, JSON_THROW_ON_ERROR),
        );

        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString($job->getId()->toString(), $list->getDisplay());
        self::assertStringContainsString('Original', $list->getDisplay());
    }

    public function testListSaysSoWhenThereAreNoJobs(): void
    {
        $tester = $this->tester(new SchedulerListCommand($this->jobs));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No scheduled jobs found.', $tester->getDisplay());
    }

    private function stored(ScheduleStatus $status = ScheduleStatus::Active): ScheduledJob
    {
        $job = ScheduledJob::create('Original', '0 3 * * *', JobType::Console, 'app:test', 'Nightly run', ['limit' => 3]);
        $job->getState()->status = $status;
        $this->rows[$job->getId()->toString()] = ScheduledJob::reconstitute(clone $job->getState());

        return $job;
    }

    private function tester(Command $command): CommandTester
    {
        return new CommandTester($command);
    }

    private function reader(): ScheduledJobInputReader
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id === 'errors.validation.failed' ? 'Validation failed.' : $id,
        );

        return new ScheduledJobInputReader(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            $translator,
        );
    }

    private function registry(): SchedulerRegistry
    {
        $registry = $this->createStub(SchedulerRegistry::class);
        $registry->method('isConsoleCommandAllowed')->willReturnCallback(
            static fn (string $command): bool => $command === 'app:test',
        );
        $registry->method('getConsoleParameterSchema')->willReturn(self::CATALOG['console']['app:test']['parameters']);
        $registry->method('getConsoleCommands')->willReturn(self::CATALOG['console']);
        $registry->method('getMessengerCommands')->willReturn(self::CATALOG['messenger']);

        return $registry;
    }

    /** Keeps copies, like the database, so a refused write leaves the stored job unchanged. */
    private function repository(): ScheduledJobRepositoryInterface
    {
        $test = $this;

        return new class ($test) implements ScheduledJobRepositoryInterface {
            public function __construct(private readonly SchedulerAdministrationCommandsTest $test)
            {
            }

            public function save(ScheduledJob $job): void
            {
                $this->test->write($job);
            }

            public function findByUuid(Uuid $uuid): ?ScheduledJob
            {
                return $this->test->read($uuid);
            }

            public function findAll(): array
            {
                return $this->test->readAll();
            }

            public function findByStatus(ScheduleStatus $status): array
            {
                return array_values(array_filter($this->test->readAll(), static fn (ScheduledJob $job): bool => $job->getStatus() === $status));
            }

            public function delete(ScheduledJob $job): void
            {
                $this->test->remove($job);
            }
        };
    }

    /** @internal the in-memory repository's storage */
    public function write(ScheduledJob $job): void
    {
        if ($this->conflictOnWrite) {
            throw new PersistenceConflict();
        }
        $this->rows[$job->getId()->toString()] = ScheduledJob::reconstitute(clone $job->getState());
    }

    /** @internal */
    public function read(Uuid $id): ?ScheduledJob
    {
        $row = $this->rows[$id->toString()] ?? null;

        return $row === null ? null : ScheduledJob::reconstitute(clone $row->getState());
    }

    /**
     * @internal
     *
     * @return list<ScheduledJob>
     */
    public function readAll(): array
    {
        return array_values(array_map(
            static fn (ScheduledJob $row): ScheduledJob => ScheduledJob::reconstitute(clone $row->getState()),
            $this->rows,
        ));
    }

    /** @internal */
    public function remove(ScheduledJob $job): void
    {
        if ($this->conflictOnWrite) {
            throw new PersistenceConflict();
        }
        unset($this->rows[$job->getId()->toString()]);
    }
}
