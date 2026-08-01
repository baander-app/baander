<?php

declare(strict_types=1);

namespace App\Tests\Functional\Scheduler\Infrastructure\Doctrine\Repository;

use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class ScheduledJobRepositoryTest extends TestCase
{
    private ScheduledJobRepositoryInterface $repository;
    private ScheduledJobPortInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->repository = $container->get(ScheduledJobRepositoryInterface::class);
        $this->service = $container->get(ScheduledJobPortInterface::class);
    }

    // ---------------------------------------------------------------
    // save + getById
    // ---------------------------------------------------------------

    public function testSaveAndFindById(): void
    {
        $job = ScheduledJob::create(
            name: 'Repository Test',
            expression: '0 */6 * * *',
            jobType: JobType::Messenger,
            command: 'App\Test\Command',
            description: 'Test job for repository',
            parameters: ['key' => 'value'],
        );

        $this->service->save($job);

        $found = $this->service->getById($job->getId());

        $this->assertNotNull($found);
        $this->assertSame('Repository Test', $found->getName());
        $this->assertSame('0 */6 * * *', $found->getExpression());
        $this->assertSame(JobType::Messenger, $found->getJobType());
        $this->assertSame('App\Test\Command', $found->getCommand());
        $this->assertSame(ScheduleStatus::Active, $found->getStatus());
        $this->assertSame('Test job for repository', $found->getDescription());
        $this->assertSame(['key' => 'value'], $found->getParameters());
    }

    public function testGetByIdReturnsNullForUnknown(): void
    {
        $result = $this->service->getById(Uuid::v4());

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // findAll
    // ---------------------------------------------------------------

    public function testFindAllReturnsAllJobs(): void
    {
        $job1 = ScheduledJob::create('Job 1', '* * * * *', JobType::Messenger, 'App\Cmd1');
        $job2 = ScheduledJob::create('Job 2', '0 0 * * *', JobType::Console, 'app:cmd2');

        $this->service->save($job1);
        $this->service->save($job2);

        $all = $this->service->findAll();

        $this->assertGreaterThanOrEqual(2, count($all));
        $names = array_map(fn (ScheduledJob $j) => $j->getName(), $all);
        $this->assertContains('Job 1', $names);
        $this->assertContains('Job 2', $names);
    }

    // ---------------------------------------------------------------
    // findByStatus
    // ---------------------------------------------------------------

    public function testFindByStatusReturnsOnlyMatching(): void
    {
        $activeJob = ScheduledJob::create('Active Job', '* * * * *', JobType::Messenger, 'App\Active');
        $pausedJob = ScheduledJob::create('Paused Job', '* * * * *', JobType::Messenger, 'App\Paused');
        $pausedJob->pause();

        $this->service->save($activeJob);
        $this->service->save($pausedJob);

        $active = $this->service->findByStatus(ScheduleStatus::Active);
        $paused = $this->service->findByStatus(ScheduleStatus::Paused);

        $activeNames = array_map(fn (ScheduledJob $j) => $j->getName(), $active);
        $pausedNames = array_map(fn (ScheduledJob $j) => $j->getName(), $paused);

        $this->assertContains('Active Job', $activeNames);
        $this->assertNotContains('Paused Job', $activeNames);
        $this->assertContains('Paused Job', $pausedNames);
        $this->assertNotContains('Active Job', $pausedNames);
    }

    // ---------------------------------------------------------------
    // State updates
    // ---------------------------------------------------------------

    public function testSaveUpdatesExistingJob(): void
    {
        $job = ScheduledJob::create('Original', '* * * * *', JobType::Messenger, 'App\Cmd');

        $this->service->save($job);
        $id = $job->getId();

        $job->pause();
        $this->service->save($job);

        $found = $this->service->getById($id);
        $this->assertNotNull($found);
        $this->assertSame(ScheduleStatus::Paused, $found->getStatus());
    }

    // ---------------------------------------------------------------
    // delete
    // ---------------------------------------------------------------

    public function testDeleteRemovesJob(): void
    {
        $job = ScheduledJob::create('To Delete', '* * * * *', JobType::Console, 'app:delete');
        $this->service->save($job);

        $id = $job->getId();
        $this->assertNotNull($this->service->getById($id));

        $this->service->delete($job);

        $this->assertNull($this->service->getById($id));
    }

    // ---------------------------------------------------------------
    // Run tracking
    // ---------------------------------------------------------------

    public function testRunTrackingPersists(): void
    {
        $job = ScheduledJob::create('Track Runs', '* * * * *', JobType::Messenger, 'App\Track');
        $this->service->save($job);

        $job->markRunning();
        $job->markSuccess('output result');
        $this->service->save($job);

        $found = $this->service->getById($job->getId());
        $this->assertNotNull($found);
        $this->assertSame(1, $found->getRunCount());
        $this->assertSame('output result', $found->getLastResult());
        $this->assertNotNull($found->getLastRunAt());
        $this->assertNull($found->getLastError());
    }

    public function testFailureTrackingPersists(): void
    {
        $job = ScheduledJob::create('Fail Track', '* * * * *', JobType::Messenger, 'App\Fail');
        $this->service->save($job);

        $job->markRunning();
        $job->markFailed('something broke');
        $this->service->save($job);

        $found = $this->service->getById($job->getId());
        $this->assertNotNull($found);
        $this->assertSame(1, $found->getRunCount());
        $this->assertSame('something broke', $found->getLastError());
        $this->assertNull($found->getLastResult());
        $this->assertNotNull($found->getLastFailureAt());
    }
}
