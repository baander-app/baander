<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Interface\Controller;

use App\Scheduler\Application\DTO\ScheduledJobInput;
use App\Scheduler\Application\Port\ScheduledJobAdministrationInterface;
use App\Scheduler\Application\Port\SchedulerManualOccurrenceRecorderInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Interface\Controller\AdminScheduledJobController;
use App\Scheduler\Interface\Request\CreateScheduledJobRequest;
use App\Scheduler\Interface\Request\UpdateScheduledJobRequest;
use App\Scheduler\Interface\Resource\ScheduledJobResource;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminScheduledJobAdministrationTest extends TestCase
{
    public function testCreateAndUpdateForwardInputAndPreserveResourcePayloadAndStatus(): void
    {
        $job = ScheduledJob::create('Original', '* * * * *', JobType::Console, 'app:test');
        $input = new ScheduledJobInput(
            'New name',
            '0 * * * *',
            'console',
            'app:test',
            'Description',
            ['limit' => 3],
        );
        $admin = $this->createMock(ScheduledJobAdministrationInterface::class);
        $admin->expects(self::once())->method('createJob')->with($input)->willReturn($job);
        $admin->expects(self::once())->method('updateJob')->with($job->getId(), $input)->willReturn($job);
        $controller = new AdminScheduledJobController(
            $admin,
            $this->createStub(SchedulerManualOccurrenceRecorderInterface::class),
        );
        $created = $controller->create(new CreateScheduledJobRequest(
            $input->name,
            $input->expression,
            $input->jobType,
            $input->command,
            $input->description,
            $input->parameters,
        ));
        $updated = $controller->update(
            $job->getId()->toString(),
            new UpdateScheduledJobRequest(
                $input->name,
                $input->expression,
                $input->jobType,
                $input->command,
                $input->description,
                $input->parameters,
            ),
        );

        self::assertSame(201, $created->getStatusCode());
        self::assertSame(200, $updated->getStatusCode());
        foreach ([$created, $updated] as $response) {
            self::assertSame(
                ['data' => ScheduledJobResource::from($job)],
                json_decode($response->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR),
            );
        }
    }

    #[DataProvider('lifecycleActions')]
    public function testLifecycleDelegatesWithoutMutatingTheReturnedResource(string $action): void
    {
        $job = ScheduledJob::create('Original', '* * * * *', JobType::Console, 'app:test');
        $admin = $this->createMock(ScheduledJobAdministrationInterface::class);
        $admin->expects(self::once())->method($action)->with($job->getId())->willReturn($job);
        $controller = new AdminScheduledJobController(
            $admin,
            $this->createStub(SchedulerManualOccurrenceRecorderInterface::class),
        );
        $response = $controller->{$action}($job->getId()->toString());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['data' => ScheduledJobResource::from($job)],
            json_decode($response->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function lifecycleActions(): iterable
    {
        foreach (['pause', 'resume', 'enable', 'disable'] as $action) {
            yield $action => [$action];
        }
    }

    public function testMissingJobsPreserve404AndDeletePreserves204(): void
    {
        $id = Uuid::generate();
        $admin = $this->createStub(ScheduledJobAdministrationInterface::class);
        $admin->method('deleteById')->willReturn(false, true);
        $controller = new AdminScheduledJobController(
            $admin,
            $this->createStub(SchedulerManualOccurrenceRecorderInterface::class),
        );
        foreach (['show', 'pause', 'resume', 'enable', 'disable', 'delete'] as $action) {
            $response = $controller->{$action}($id->toString());
            self::assertSame(404, $response->getStatusCode());
            self::assertSame(
                ['error' => ['message' => 'Scheduled job not found.', 'code' => 404]],
                json_decode($response->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR),
            );
        }
        self::assertSame(
            404,
            $controller->update(
                $id->toString(),
                new UpdateScheduledJobRequest('Updated', '* * * * *', 'console', 'app:test'),
            )->getStatusCode(),
        );
        $deleted = $controller->delete($id->toString());
        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame('{}', $deleted->getContent());
    }

    public function testCommandCatalogPreservesItsTwoCollections(): void
    {
        $catalog = [
            'messenger' => [],
            'console' => ['app:test' => ['description' => 'A command', 'parameters' => []]],
        ];
        $admin = $this->createMock(ScheduledJobAdministrationInterface::class);
        $admin->expects(self::once())->method('availableCommands')->willReturn($catalog);
        $controller = new AdminScheduledJobController(
            $admin,
            $this->createStub(SchedulerManualOccurrenceRecorderInterface::class),
        );
        $response = $controller->commands();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['data' => $catalog],
            json_decode($response->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
