<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recommendation;

use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The recommendation admin routes and their `app:recommendation:*` commands reach the same
 * use cases, so both paths leave the same job records and report the same outcomes.
 */
final class RecommendationAdministrationTest extends TestCase
{
    private const string PATH = '/api/admin/recommendations';

    public function testCliGenerateLeavesAJobRecordThatTheJobListShowsAndAMonitorRecord(): void
    {
        $generate = $this->command('app:recommendation:generate');
        self::assertSame(Command::SUCCESS, $generate->execute(['--mode' => 'incremental']), $generate->getDisplay());
        self::assertSame(1, preg_match('/Recommendation job\s*:?\s*([A-Za-z0-9_-]{21})/', $generate->getDisplay(), $job), $generate->getDisplay());
        self::assertSame(1, preg_match('/Job monitor ID\s*:?\s*([A-Za-z0-9_-]{21})/', $generate->getDisplay(), $monitor), $generate->getDisplay());

        $list = $this->command('app:recommendation:job:list');
        self::assertSame(Command::SUCCESS, $list->execute(['--json' => true]));
        $listed = $this->find(json_decode($list->getDisplay(), true, flags: JSON_THROW_ON_ERROR), $job[1]);
        self::assertSame('completed', $listed['status']);
        self::assertFalse($listed['is_full']);
        self::assertSame('cli', $listed['metadata']['triggered_by']);

        $admin = $this->createAdminUser();
        $api = $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '/jobs', $admin), 200, 'data')['data'];
        self::assertSame($listed, $this->find($api, $job[1]));

        $record = $this->jobMonitor()->job($monitor[1]);
        self::assertSame('GenerateRecommendationsCommand', $record->name);
        self::assertSame('finished', $record->status->value);
    }

    public function testARequeuedJobIsDispatchedAndReachesATerminalStatusOnBothPaths(): void
    {
        $admin = $this->createAdminUser();

        $failed = $this->failedJob();
        $requeued = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', self::PATH . '/jobs/' . $failed . '/requeue', $admin),
            201,
            'data',
        )['data'];
        $show = $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '/jobs/' . $requeued['public_id'], $admin), 200, 'data')['data'];
        self::assertSame('completed', $show['status']);
        self::assertSame($failed, $show['metadata']['requeued_from']);

        $cancelled = $this->cancelledJob();
        $cli = $this->command('app:recommendation:job:requeue');
        self::assertSame(Command::SUCCESS, $cli->execute(['publicId' => $cancelled]), $cli->getDisplay());
        self::assertSame(1, preg_match('/Recommendation job\s*:?\s*([A-Za-z0-9_-]{21})/', $cli->getDisplay(), $job), $cli->getDisplay());
        $show = $this->command('app:recommendation:job:show');
        self::assertSame(Command::SUCCESS, $show->execute(['publicId' => $job[1], '--json' => true]));
        $shown = json_decode($show->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('completed', $shown['status']);
        self::assertSame($cancelled, $shown['metadata']['requeued_from']);
        self::assertSame('cli', $shown['metadata']['requeued_by']);

        // Only a failed or cancelled job can be requeued: the completed one is a conflict on both paths.
        $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/jobs/' . $job[1] . '/requeue', $admin), 409);
        self::assertSame(Command::FAILURE, $this->command('app:recommendation:job:requeue')->execute(['publicId' => $job[1]]));
    }

    public function testCancellingAPendingJobMarksItCancelledAndACompletedJobIsAConflictOnBothPaths(): void
    {
        $admin = $this->createAdminUser();

        $pending = $this->jobs()->create(isFull: true)->getPublicId()->toString();
        self::assertSame(204, $this->authenticatedRequest('DELETE', self::PATH . '/jobs/' . $pending, $admin)->getStatusCode());
        self::assertSame('cancelled', $this->jobStatus($pending));
        // Cancelling again changes nothing and succeeds.
        self::assertSame(204, $this->authenticatedRequest('DELETE', self::PATH . '/jobs/' . $pending, $admin)->getStatusCode());

        $other = $this->jobs()->create(isFull: false)->getPublicId()->toString();
        $cancel = $this->command('app:recommendation:job:cancel');
        self::assertSame(Command::SUCCESS, $cancel->execute(['publicId' => $other]), $cancel->getDisplay());
        self::assertSame('cancelled', $this->jobStatus($other));

        $completed = $this->completedJob();
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::PATH . '/jobs/' . $completed, $admin), 409);
        $conflict = $this->command('app:recommendation:job:cancel');
        self::assertSame(Command::FAILURE, $conflict->execute(['publicId' => $completed]));
        self::assertSame('completed', $this->jobStatus($completed));

        $unknown = (new PublicId())->toString();
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', self::PATH . '/jobs/' . $unknown, $admin), 404);
        self::assertSame(Command::FAILURE, $this->command('app:recommendation:job:cancel')->execute(['publicId' => $unknown]));
    }

    public function testStatsJsonContainsTheCoverageSourceQualityAndFreshnessPayloads(): void
    {
        $admin = $this->createAdminUser();

        $stats = $this->command('app:recommendation:stats');
        self::assertSame(Command::SUCCESS, $stats->execute(['--json' => true]));

        self::assertSame(
            [
                'coverage' => $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '/coverage', $admin), 200)['data'],
                'source_quality' => $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '/source-quality', $admin), 200)['data'],
                'freshness' => $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH . '/freshness', $admin), 200)['data'],
            ],
            json_decode($stats->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testAnInvalidModeIsRejectedOnBothPaths(): void
    {
        $admin = $this->createAdminUser();

        $this->assertJsonResponse($this->authenticatedRequest('POST', self::PATH . '/generate', $admin, ['mode' => 'Full']), 422);
        self::assertSame(Command::INVALID, $this->command('app:recommendation:generate')->execute(['--mode' => 'Full']));
    }

    public function testAGuardedSaveKeepsACancellationStoredByAnotherProcess(): void
    {
        $job = $this->jobs()->create(isFull: false);
        $job->markInProgress(0);
        self::assertTrue($this->jobs()->saveIfStatusIn($job, RecommendationJobStatus::Pending));
        // An admin cancels the job from another process; this process still holds it in progress.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE recommendation_jobs SET status = 'cancelled' WHERE id = ?",
            [$job->getId()->toString()],
        );

        $job->markCompleted(['collaborative' => 0, 'content' => 0, 'genre' => 0]);
        self::assertFalse($this->jobs()->saveIfStatusIn($job, RecommendationJobStatus::InProgress));

        self::assertSame(RecommendationJobStatus::Cancelled, $this->jobs()->storedStatus($job->getId()));
        self::assertSame('cancelled', $this->jobStatus($job->getPublicId()->toString()));
    }

    private function failedJob(): string
    {
        $job = $this->jobs()->create(isFull: false, metadata: ['mode' => 'incremental']);
        $job->markFailed('Worker crashed');
        $this->jobs()->save($job);

        return $job->getPublicId()->toString();
    }

    private function cancelledJob(): string
    {
        $job = $this->jobs()->create(isFull: false, metadata: ['mode' => 'incremental']);
        $job->markCancelled();
        $this->jobs()->save($job);

        return $job->getPublicId()->toString();
    }

    private function completedJob(): string
    {
        $job = $this->jobs()->create(isFull: false);
        $job->markInProgress(0);
        $job->markCompleted(['collaborative' => 0, 'content' => 0, 'genre' => 0]);
        $this->jobs()->save($job);

        return $job->getPublicId()->toString();
    }

    private function jobStatus(string $publicId): string
    {
        $this->entityManager->clear();
        $job = $this->jobs()->getByPublicId(PublicId::fromString($publicId));
        self::assertInstanceOf(RecommendationJob::class, $job);

        return $job->getStatus()->value;
    }

    /**
     * @param list<array<string, mixed>> $jobs
     *
     * @return array<string, mixed>
     */
    private function find(array $jobs, string $publicId): array
    {
        foreach ($jobs as $job) {
            if ($job['public_id'] === $publicId) {
                return $job;
            }
        }

        self::fail(sprintf('Job %s is not listed.', $publicId));
    }

    private function jobs(): RecommendationJobPortInterface
    {
        $jobs = static::getContainer()->get(RecommendationJobPortInterface::class);
        self::assertInstanceOf(RecommendationJobPortInterface::class, $jobs);

        return $jobs;
    }

    private function jobMonitor(): JobMonitorAdministrationInterface
    {
        $monitor = static::getContainer()->get(JobMonitorAdministrationInterface::class);
        self::assertInstanceOf(JobMonitorAdministrationInterface::class, $monitor);

        return $monitor;
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
