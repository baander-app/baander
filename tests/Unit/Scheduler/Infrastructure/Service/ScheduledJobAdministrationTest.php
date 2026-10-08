<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Infrastructure\Service;

use App\Scheduler\Application\DTO\ScheduledJobInput;
use App\Scheduler\Application\Exception\InvalidScheduledJob;
use App\Scheduler\Application\Exception\ScheduledJobConflict;
use App\Scheduler\Domain\Exception\ScheduledJobConflict as PersistenceConflict;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Scheduler\Infrastructure\Service\ScheduledJobService;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduledJobAdministrationTest extends TestCase
{
    public function testCreateAndUpdateValidateRegisteredCommandsAndPersistCompleteInput(): void
    {
        $repository = $this->createMock(ScheduledJobRepositoryInterface::class);
        $saved = [];
        $repository->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(static function (ScheduledJob $job) use (&$saved): void {
                $saved[] = clone $job->getState();
            });
        $registry = $this->validRegistry();
        $service = new ScheduledJobService($repository, $registry);
        $job = $service->createJob(new ScheduledJobInput(
            'Original',
            '* * * * *',
            'console',
            'app:test',
            'Description',
            ['limit' => 3],
        ));
        // Configure lookup after creation so the update uses the persisted identity.
        $repository->method('findByUuid')->willReturn($job);
        $updated = $service->updateJob(
            $job->getId(),
            new ScheduledJobInput('Changed', '0 * * * *', 'console', 'app:test', null, ['limit' => 5]),
        );

        self::assertSame($job, $updated);
        self::assertSame('Original', $saved[0]->name);
        self::assertSame('Changed', $saved[1]->name);
        self::assertSame(['limit' => 5], $job->getParameters());
        self::assertSame('0 * * * *', $job->getExpression());
        self::assertNull($job->getDescription());
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidUpdateCannotMutateOrPersistJob(ScheduledJobInput $input): void
    {
        $job = $this->job();
        $before = clone $job->getState();
        $repository = $this->createMock(ScheduledJobRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($job);
        $repository->expects(self::never())->method('save');
        $service = new ScheduledJobService($repository, $this->validRegistry());

        try {
            $service->updateJob($job->getId(), $input);
            self::fail('Invalid administration input must fail.');
        } catch (InvalidScheduledJob $error) {
            self::assertInstanceOf(InvalidInputException::class, $error);
            self::assertEquals($before, $job->getState());
        }
    }

    #[DataProvider('repeatedTransitions')]
    public function testRepeatingALifecycleActionSucceedsWithoutWriting(string $action, ScheduleStatus $status): void
    {
        $job = $this->job();
        $job->getState()->status = $status;
        $before = clone $job->getState();
        $repository = $this->createMock(ScheduledJobRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($job);
        $repository->expects(self::never())->method('save');
        $service = new ScheduledJobService($repository, $this->createStub(SchedulerRegistry::class));

        self::assertSame($job, $service->{$action}($job->getId()));
        self::assertEquals($before, $job->getState());
    }

    /** @return iterable<string, array{string, ScheduleStatus}> */
    public static function repeatedTransitions(): iterable
    {
        yield 'pause a paused job' => ['pause', ScheduleStatus::Paused];
        yield 'resume an active job' => ['resume', ScheduleStatus::Active];
        yield 'enable an active job' => ['enable', ScheduleStatus::Active];
        yield 'enable a paused job' => ['enable', ScheduleStatus::Paused];
        yield 'disable a disabled job' => ['disable', ScheduleStatus::Disabled];
    }

    #[DataProvider('disabledTransitions')]
    public function testPausingOrResumingADisabledJobIsAConflict(string $action, string $message): void
    {
        $job = $this->job();
        $job->disable();
        $repository = $this->createMock(ScheduledJobRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($job);
        $repository->expects(self::never())->method('save');
        $service = new ScheduledJobService($repository, $this->createStub(SchedulerRegistry::class));

        try {
            $service->{$action}($job->getId());
            self::fail('A disabled job must refuse the change.');
        } catch (ConflictException $error) {
            self::assertSame($message, $error->getMessage());
            self::assertSame(ScheduleStatus::Disabled, $job->getStatus());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function disabledTransitions(): iterable
    {
        yield 'pause' => ['pause', 'A disabled job cannot be paused. Enable it first.'];
        yield 'resume' => ['resume', 'A disabled job cannot be resumed. Enable it first.'];
    }

    /** @return iterable<string, array{ScheduledJobInput}> */
    public static function invalidInputs(): iterable
    {
        yield 'unregistered' => [new ScheduledJobInput('Changed', '* * * * *', 'console', 'app:rogue')];
        yield 'unknown parameter' => [new ScheduledJobInput(
            'Changed',
            '* * * * *',
            'console',
            'app:test',
            parameters: ['other' => 1],
        )];
        yield 'missing required' => [new ScheduledJobInput('Changed', '* * * * *', 'console', 'app:test')];
        yield 'incorrect type' => [new ScheduledJobInput(
            'Changed',
            '* * * * *',
            'console',
            'app:test',
            parameters: ['limit' => '1'],
        )];
        yield 'invalid cron' => [new ScheduledJobInput(
            'Changed',
            'invalid',
            'console',
            'app:test',
            parameters: ['limit' => 1],
        )];
        yield 'invalid job type' => [new ScheduledJobInput('Changed', '* * * * *', 'invalid', 'app:test')];
        yield 'empty name' => [new ScheduledJobInput(' ', '* * * * *', 'console', 'app:test')];
    }

    #[DataProvider('transitions')]
    public function testLifecycleWritesPersistAndTranslateOnlyPersistenceConflicts(
        string $action,
        ScheduleStatus $initial,
        ScheduleStatus $expected,
    ): void
    {
        $job = $this->job();
        $job->getState()->status = $initial;
        $repository = $this->createMock(ScheduledJobRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($job);
        $repository->expects(self::once())
            ->method('save')
            ->with($job)
            ->willThrowException($conflict = new PersistenceConflict());
        $service = new ScheduledJobService($repository, $this->createStub(SchedulerRegistry::class));
        try {
            $service->{$action}($job->getId());
            self::fail('Persistence conflict must be translated.');
        } catch (ScheduledJobConflict $error) {
            self::assertSame($conflict, $error->getPrevious());
            self::assertSame($expected, $job->getStatus());
        }
    }

    /** @return iterable<string, array{string, ScheduleStatus, ScheduleStatus}> */
    public static function transitions(): iterable
    {
        yield 'pause' => ['pause', ScheduleStatus::Active, ScheduleStatus::Paused];
        yield 'resume' => ['resume', ScheduleStatus::Paused, ScheduleStatus::Active];
        yield 'enable' => ['enable', ScheduleStatus::Disabled, ScheduleStatus::Active];
        yield 'disable' => ['disable', ScheduleStatus::Active, ScheduleStatus::Disabled];
    }

    public function testDeleteTranslatesConflictAndMissingWritesReturnAbsence(): void
    {
        $job = $this->job();
        $repository = $this->createMock(ScheduledJobRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($job, null, null, null, null, null, null);
        $repository->expects(self::once())
            ->method('delete')
            ->with($job)
            ->willThrowException($conflict = new PersistenceConflict());
        $repository->expects(self::never())->method('save');
        $service = new ScheduledJobService($repository, $this->createStub(SchedulerRegistry::class));
        try {
            $service->deleteById($job->getId());
            self::fail('Delete conflict must be translated.');
        } catch (ScheduledJobConflict $error) {
            self::assertSame($conflict, $error->getPrevious());
        }
        self::assertFalse($service->deleteById(Uuid::generate()));
        self::assertNull($service->updateJob(
            Uuid::generate(),
            new ScheduledJobInput('Changed', '* * * * *', 'console', 'app:test'),
        ));
        foreach (['pause', 'resume', 'enable', 'disable'] as $action) {
            self::assertNull($service->{$action}(Uuid::generate()));
        }
    }

    public function testUnexpectedStorageErrorsPropagateUnchanged(): void
    {
        $job = $this->job();
        $repository = $this->createStub(ScheduledJobRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($job);
        $repository->method('save')
            ->willThrowException($failure = new \RuntimeException('Storage unavailable'));
        $service = new ScheduledJobService($repository, $this->createStub(SchedulerRegistry::class));
        try {
            $service->pause($job->getId());
            self::fail('Storage failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    #[DataProvider('inputWriteActions')]
    public function testCreateAndUpdateTranslatePersistenceConflicts(string $action): void
    {
        $job = $this->job();
        $repository = $this->createStub(ScheduledJobRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($job);
        $repository->method('save')->willThrowException($conflict = new PersistenceConflict());
        $service = new ScheduledJobService($repository, $this->validRegistry());
        $input = new ScheduledJobInput(
            'Valid',
            '* * * * *',
            'console',
            'app:test',
            parameters: ['limit' => 3],
        );
        try {
            if ($action === 'create') {
                $service->createJob($input);
            } else {
                $service->updateJob($job->getId(), $input);
            }
            self::fail('Write conflict must be translated.');
        } catch (ScheduledJobConflict $error) {
            self::assertSame($conflict, $error->getPrevious());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function inputWriteActions(): iterable
    {
        yield 'create' => ['create'];
        yield 'update' => ['update'];
    }

    private function validRegistry(): SchedulerRegistry
    {
        $registry = $this->createStub(SchedulerRegistry::class);
        $registry->method('isConsoleCommandAllowed')->willReturnCallback(
            static fn (string $command): bool => $command === 'app:test',
        );
        $registry->method('getConsoleParameterSchema')->willReturn([
            'limit' => ['type' => 'int', 'required' => true],
        ]);
        return $registry;
    }

    private function job(): ScheduledJob
    {
        return ScheduledJob::create('Original', '* * * * *', JobType::Console, 'app:test');
    }
}
