<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler\Domain\Model;

use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Model\ScheduledJobState;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScheduledJobTest extends TestCase
{
    // ---------------------------------------------------------------
    // create()
    // ---------------------------------------------------------------

    public function testCreateSetsDefaults(): void
    {
        $job = ScheduledJob::create(
            name: 'Scan Library',
            expression: '0 */6 * * *',
            jobType: JobType::Messenger,
            command: 'App\Library\Application\Command\ScanLibraryCommand',
            description: 'Scan music library',
            parameters: [],
        );

        $this->assertInstanceOf(Uuid::class, $job->getId());
        $this->assertSame('Scan Library', $job->getName());
        $this->assertSame('0 */6 * * *', $job->getExpression());
        $this->assertSame(JobType::Messenger, $job->getJobType());
        $this->assertSame('App\Library\Application\Command\ScanLibraryCommand', $job->getCommand());
        $this->assertSame(ScheduleStatus::Active, $job->getStatus());
        $this->assertSame('Scan music library', $job->getDescription());
        $this->assertSame([], $job->getParameters());
        $this->assertSame(0, $job->getRunCount());
        $this->assertNull($job->getLastRunAt());
        $this->assertNull($job->getLastResult());
        $this->assertNull($job->getLastFailureAt());
        $this->assertNull($job->getLastError());
        $this->assertNotNull($job->getNextRunAt());
        $this->assertNotNull($job->getCreatedAt());
        $this->assertNotNull($job->getUpdatedAt());
    }

    public function testCreateWithOptionalFieldsNull(): void
    {
        $job = ScheduledJob::create(
            name: 'Test',
            expression: '* * * * *',
            jobType: JobType::Console,
            command: 'app:test',
        );

        $this->assertNull($job->getDescription());
        $this->assertSame([], $job->getParameters());
    }

    public function testCreateWithParameters(): void
    {
        $job = ScheduledJob::create(
            name: 'Test',
            expression: '* * * * *',
            jobType: JobType::Messenger,
            command: 'App\TestCommand',
            parameters: ['libraryId' => 'abc-123'],
        );

        $this->assertSame(['libraryId' => 'abc-123'], $job->getParameters());
    }

    // ---------------------------------------------------------------
    // reconstitute()
    // ---------------------------------------------------------------

    public function testReconstituteRestoresState(): void
    {
        $now = new DateTimeImmutable();
        $id = Uuid::v4();

        $state = new ScheduledJobState(
            id: $id,
            name: 'Reconstituted',
            expression: '0 0 * * *',
            jobType: JobType::Console,
            command: 'app:nightly',
            status: ScheduleStatus::Paused,
            description: null,
            parameters: [],
            createdAt: $now,
            updatedAt: $now,
            lastRunAt: $now,
            nextRunAt: null,
            lastResult: 'ok',
            runCount: 5,
            lastFailureAt: null,
            lastError: null,
        );

        $job = ScheduledJob::reconstitute($state);

        $this->assertSame($id, $job->getId());
        $this->assertSame('Reconstituted', $job->getName());
        $this->assertSame(ScheduleStatus::Paused, $job->getStatus());
        $this->assertSame(5, $job->getRunCount());
        $this->assertSame('ok', $job->getLastResult());
        $this->assertSame($now, $job->getLastRunAt());
    }

    // ---------------------------------------------------------------
    // State machine: pause/resume/enable/disable
    // ---------------------------------------------------------------

    public function testPauseFromActive(): void
    {
        $job = $this->createActiveJob();
        $job->pause();

        $this->assertSame(ScheduleStatus::Paused, $job->getStatus());
    }

    public function testPauseFromPausedThrows(): void
    {
        $job = $this->createActiveJob();
        $job->pause();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Can only pause an active job');

        $job->pause();
    }

    public function testPauseFromDisabledThrows(): void
    {
        $job = $this->createActiveJob();
        $job->disable();

        $this->expectException(RuntimeException::class);
        $job->pause();
    }

    public function testResumeFromPaused(): void
    {
        $job = $this->createActiveJob();
        $job->pause();

        $this->assertSame(ScheduleStatus::Paused, $job->getStatus());

        $job->resume();

        $this->assertSame(ScheduleStatus::Active, $job->getStatus());
        $this->assertNotNull($job->getNextRunAt());
    }

    public function testResumeFromActiveThrows(): void
    {
        $job = $this->createActiveJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not paused');

        $job->resume();
    }

    public function testResumeFromDisabledThrows(): void
    {
        $job = $this->createActiveJob();
        $job->disable();

        $this->expectException(RuntimeException::class);
        $job->resume();
    }

    public function testEnableFromDisabled(): void
    {
        $job = $this->createActiveJob();
        $job->disable();

        $this->assertSame(ScheduleStatus::Disabled, $job->getStatus());

        $job->enable();

        $this->assertSame(ScheduleStatus::Active, $job->getStatus());
        $this->assertNotNull($job->getNextRunAt());
    }

    public function testEnableFromActiveThrows(): void
    {
        $job = $this->createActiveJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Can only enable a disabled job');

        $job->enable();
    }

    public function testEnableFromPausedThrows(): void
    {
        $job = $this->createActiveJob();
        $job->pause();

        $this->expectException(RuntimeException::class);
        $job->enable();
    }

    public function testDisableFromActive(): void
    {
        $job = $this->createActiveJob();
        $job->disable();

        $this->assertSame(ScheduleStatus::Disabled, $job->getStatus());
    }

    public function testDisableFromPaused(): void
    {
        $job = $this->createActiveJob();
        $job->pause();
        $job->disable();

        $this->assertSame(ScheduleStatus::Disabled, $job->getStatus());
    }

    public function testDisableFromDisabledIsNoop(): void
    {
        $job = $this->createActiveJob();
        $job->disable();
        $updatedAt = $job->getUpdatedAt();

        $job->disable();

        // Status stays disabled; updatedAt does not change
        $this->assertSame(ScheduleStatus::Disabled, $job->getStatus());
        $this->assertEquals($updatedAt, $job->getUpdatedAt());
    }

    public function testFullLifecycle(): void
    {
        $job = $this->createActiveJob();

        $job->pause();
        $this->assertSame(ScheduleStatus::Paused, $job->getStatus());

        $job->disable();
        $this->assertSame(ScheduleStatus::Disabled, $job->getStatus());

        $job->enable();
        $this->assertSame(ScheduleStatus::Active, $job->getStatus());
    }

    // ---------------------------------------------------------------
    // update()
    // ---------------------------------------------------------------

    public function testUpdateChangesFields(): void
    {
        $job = $this->createActiveJob();

        $job->update(
            name: 'New Name',
            expression: '0 */2 * * *',
            jobType: JobType::Console,
            command: 'app:new-command',
            description: 'Updated desc',
            parameters: ['key' => 'val'],
        );

        $this->assertSame('New Name', $job->getName());
        $this->assertSame('0 */2 * * *', $job->getExpression());
        $this->assertSame(JobType::Console, $job->getJobType());
        $this->assertSame('app:new-command', $job->getCommand());
        $this->assertSame('Updated desc', $job->getDescription());
        $this->assertSame(['key' => 'val'], $job->getParameters());
    }

    public function testUpdateRecalculatesNextRunWhenExpressionChanges(): void
    {
        $job = $this->createActiveJob();
        $oldNextRun = $job->getNextRunAt();

        $job->update(
            name: $job->getName(),
            expression: '0 0 1 1 *',
            jobType: $job->getJobType(),
            command: $job->getCommand(),
            description: $job->getDescription(),
            parameters: $job->getParameters(),
        );

        // Yearly cron should produce a different next-run than every-6-hours
        $this->assertNotEquals($oldNextRun?->format('Y-m-d'), $job->getNextRunAt()?->format('Y-m-d'));
    }

    // ---------------------------------------------------------------
    // markRunning / markSuccess / markFailed
    // ---------------------------------------------------------------

    public function testMarkRunningSetsLastRunAt(): void
    {
        $job = $this->createActiveJob();
        $this->assertNull($job->getLastRunAt());

        $job->markRunning();

        $this->assertNotNull($job->getLastRunAt());
    }

    public function testMarkSuccessIncrementsRunCount(): void
    {
        $job = $this->createActiveJob();
        $this->assertSame(0, $job->getRunCount());

        $job->markRunning();
        $job->markSuccess('output');

        $this->assertSame(1, $job->getRunCount());
        $this->assertSame('output', $job->getLastResult());
        $this->assertNull($job->getLastFailureAt());
        $this->assertNull($job->getLastError());
    }

    public function testMarkSuccessClearsPreviousFailure(): void
    {
        $job = $this->createActiveJob();
        $job->markRunning();
        $job->markFailed('some error');

        $job->markRunning();
        $job->markSuccess('ok');

        $this->assertNull($job->getLastFailureAt());
        $this->assertNull($job->getLastError());
        $this->assertSame('ok', $job->getLastResult());
    }

    public function testMarkFailedSetsErrorFields(): void
    {
        $job = $this->createActiveJob();
        $job->markRunning();
        $job->markFailed('timeout');

        $this->assertSame(1, $job->getRunCount());
        $this->assertNull($job->getLastResult());
        $this->assertNotNull($job->getLastFailureAt());
        $this->assertSame('timeout', $job->getLastError());
    }

    public function testMarkFailedClearsPreviousSuccessResult(): void
    {
        $job = $this->createActiveJob();
        $job->markRunning();
        $job->markSuccess('ok');

        $job->markRunning();
        $job->markFailed('crash');

        $this->assertNull($job->getLastResult());
        $this->assertSame('crash', $job->getLastError());
    }

    public function testMultipleRunsIncrementCount(): void
    {
        $job = $this->createActiveJob();

        for ($i = 1; $i <= 3; $i++) {
            $job->markRunning();
            $job->markSuccess('run ' . $i);
        }

        $this->assertSame(3, $job->getRunCount());
        $this->assertSame('run 3', $job->getLastResult());
    }

    // ---------------------------------------------------------------
    // isDue()
    // ---------------------------------------------------------------

    public function testIsDueReturnsFalseForPausedJob(): void
    {
        $job = $this->createActiveJob();
        $job->pause();

        $this->assertFalse($job->isDue(new DateTimeImmutable()));
    }

    public function testIsDueReturnsFalseForDisabledJob(): void
    {
        $job = $this->createActiveJob();
        $job->disable();

        $this->assertFalse($job->isDue(new DateTimeImmutable()));
    }

    public function testIsDueWithEveryMinuteExpression(): void
    {
        $job = $this->createActiveJob('* * * * *');

        $this->assertTrue($job->isDue(new DateTimeImmutable()));
    }

    // ---------------------------------------------------------------
    // getState()
    // ---------------------------------------------------------------

    public function testGetStateReturnsCurrentState(): void
    {
        $job = $this->createActiveJob();
        $state = $job->getState();

        $this->assertInstanceOf(ScheduledJobState::class, $state);
        $this->assertSame($job->getId(), $state->id);
        $this->assertSame($job->getName(), $state->name);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createActiveJob(string $expression = '0 */6 * * *'): ScheduledJob
    {
        return ScheduledJob::create(
            name: 'Test Job',
            expression: $expression,
            jobType: JobType::Messenger,
            command: 'App\Test\Command',
            description: 'A test job',
            parameters: [],
        );
    }
}
