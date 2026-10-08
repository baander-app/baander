<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Application\CancellableJobInterface;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The job monitor API and the app:monitor:* commands go through one port, so they agree.
 */
final class JobMonitorControllerTest extends TestCase
{
    /** @var list<string> cancel flags this test set */
    private array $cancelFlags = [];

    protected function tearDown(): void
    {
        if ($this->cancelFlags !== []) {
            static::getContainer()->get(RedisClientFactory::class)->borrow(
                fn (\Redis $redis): mixed => $redis->del(...array_map(static fn (string $jobId): string => 'job_cancel:' . $jobId, $this->cancelFlags)),
            );
        }

        parent::tearDown();
    }

    public function testJobDetailReportsTheWholeRunTimeOfItsCurrentAttempt(): void
    {
        $service = static::getContainer()->get(JobMonitorService::class);
        $attempt = $service->startAttempt('detail-duration-job', 'ExtractAlbumCoverCommand', 'async', null, true);
        $service->markFinished('detail-duration-job', $attempt);
        // A run of a minute and a half; the old detail reported only the seconds part (30).
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE job_monitors SET started_at = finished_at - INTERVAL '90 seconds' WHERE job_id = 'detail-duration-job'",
        );
        $this->entityManager->clear();

        $job = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/monitor/jobs/detail-duration-job', $this->createAdminUser()),
            200,
            'data',
        )['data'];

        self::assertSame('finished', $job['status']);
        self::assertSame(1, $job['attempt']);
        self::assertEquals(90.0, $job['duration']);

        $tester = $this->command('app:monitor:job:show');
        self::assertSame(Command::SUCCESS, $tester->execute(['jobId' => 'detail-duration-job', '--json' => true]));
        self::assertEquals($job, $this->json($tester));
    }

    public function testRetryDispatchesAFailedJobOnceUnderANewIdAndAuditsWhoRetriedIt(): void
    {
        $admin = $this->createAdminUser();
        $this->failedJob('retry-from-web');
        $this->failedJob('retry-from-cli');

        $web = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/monitor/jobs/retry-from-web/retry', $admin),
            200,
            'data',
        )['data'];
        $tester = $this->command('app:monitor:job:retry');
        self::assertSame(Command::SUCCESS, $tester->execute(['jobId' => 'retry-from-cli']), $tester->getDisplay());

        $sent = $this->async()->getSent();
        self::assertCount(2, $sent);
        $newJobIds = array_map(static fn (Envelope $envelope): ?string => $envelope->last(JobIdStamp::class)?->jobId->toString(), $sent);
        self::assertSame($web['newJobId'], $newJobIds[0]);
        self::assertStringContainsString((string) $newJobIds[1], $tester->getDisplay());
        self::assertNotContains('retry-from-web', $newJobIds);
        self::assertNotContains('retry-from-cli', $newJobIds);
        self::assertInstanceOf(FailureTransportProbe::class, $sent[1]->getMessage());

        $rows = $this->entityManager->getConnection()->fetchAllKeyValue(
            "SELECT job_id, audit_log FROM job_monitors WHERE job_id IN ('retry-from-web', 'retry-from-cli') AND retried",
        );
        self::assertSame($admin->getEmail(), json_decode($rows['retry-from-web'], true, flags: JSON_THROW_ON_ERROR)[0]['userId']);
        $cliAudit = json_decode($rows['retry-from-cli'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('cli', $cliAudit[0]['userId']);
        self::assertSame($newJobIds[1], $cliAudit[0]['newJobId']);

        // A retried job is not retried again on either path.
        $this->entityManager->clear();
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/monitor/jobs/retry-from-cli/retry', $admin), 409);
        self::assertSame(Command::FAILURE, $tester->execute(['jobId' => 'retry-from-web']));
        self::assertCount(2, $this->async()->getSent());
    }

    public function testRetryingARunningJobIsAConflictOnBothPaths(): void
    {
        static::getContainer()->get(JobMonitorService::class)->startAttempt('retry-running', 'FailureTransportProbe', 'async', null, true);

        $error = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/monitor/jobs/retry-running/retry', $this->createAdminUser()),
            409,
        );
        $tester = $this->command('app:monitor:job:retry');

        self::assertSame('Only failed jobs can be retried.', $error['error']['message']);
        self::assertSame(Command::FAILURE, $tester->execute(['jobId' => 'retry-running']));
        self::assertStringContainsString('Only failed jobs can be retried.', $tester->getDisplay());
        self::assertSame([], $this->async()->getSent());
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/monitor/jobs/retry-missing/retry', $this->createAdminUser()), 404);
        self::assertSame(Command::FAILURE, $tester->execute(['jobId' => 'retry-missing']));
    }

    public function testCancellingSetsTheFlagTheJobReadsAndAFinishedJobCannotBeCancelled(): void
    {
        $admin = $this->createAdminUser();
        $service = static::getContainer()->get(JobMonitorService::class);
        $this->cancelFlags = ['cancel-web', 'cancel-cli', 'cancel-finished'];
        $service->startAttempt('cancel-web', 'FailureTransportProbe', 'async', null, true);
        $service->startAttempt('cancel-cli', 'FailureTransportProbe', 'async', null, true);
        $service->markFinished('cancel-finished', $service->startAttempt('cancel-finished', 'FailureTransportProbe', 'async', null, true));
        $cancellation = static::getContainer()->get(CancellableJobInterface::class);

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/monitor/jobs/cancel-web/cancel', $admin), 200);
        self::assertSame(Command::SUCCESS, $this->command('app:monitor:job:cancel')->execute(['jobId' => 'cancel-cli']));

        foreach (['cancel-web', 'cancel-cli'] as $jobId) {
            try {
                $cancellation->checkCancellation($jobId);
                self::fail(sprintf('Job %s must read its cancel flag.', $jobId));
            } catch (JobCancelledException) {
            }
        }

        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/monitor/jobs/cancel-finished/cancel', $admin), 409);
        self::assertSame('Finished jobs cannot be cancelled.', $error['error']['message']);
        self::assertSame(Command::FAILURE, $this->command('app:monitor:job:cancel')->execute(['jobId' => 'cancel-finished']));
        $cancellation->checkCancellation('cancel-finished');
    }

    public function testTheJobListMatchesTheApiWithTheSameFiltersAndItsCursorContinuesIt(): void
    {
        $admin = $this->createAdminUser();
        $service = static::getContainer()->get(JobMonitorService::class);
        for ($index = 0; $index < 7; ++$index) {
            $this->failedJob('list-failed-' . $index);
        }
        $service->markFinished('list-finished', $service->startAttempt('list-finished', 'FailureTransportProbe', 'async', null, true));
        $this->entityManager->clear();

        $api = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/monitor/jobs?status=failed&limit=5', $admin), 200)['data'];
        $tester = $this->command('app:monitor:jobs');
        self::assertSame(Command::SUCCESS, $tester->execute(['--status' => 'failed', '--limit' => '5', '--json' => true]));
        $cli = $this->json($tester);

        self::assertEquals($api, $cli);
        self::assertCount(5, $cli['items']);
        self::assertTrue($cli['has_next_page']);
        self::assertSame(['failed'], array_values(array_unique(array_column($cli['items'], 'status'))));

        $apiNext = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/monitor/jobs?status=failed&limit=5&cursor=' . urlencode((string) $api['next_cursor']), $admin),
            200,
        )['data'];
        self::assertSame(Command::SUCCESS, $tester->execute(['--status' => 'failed', '--limit' => '5', '--cursor' => $cli['next_cursor'], '--json' => true]));
        $cliNext = $this->json($tester);

        self::assertEquals($apiNext, $cliNext);
        self::assertCount(2, $cliNext['items']);
        self::assertFalse($cliNext['has_next_page']);
        $all = [...array_column($cli['items'], 'jobId'), ...array_column($cliNext['items'], 'jobId')];
        self::assertCount(7, array_unique($all));

        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/monitor/jobs?status=stuck', $admin), 422);
        self::assertSame(Command::INVALID, $tester->execute(['--status' => 'stuck']));
    }

    public function testTheStatusMatchesTheApi(): void
    {
        static::getContainer()->get(JobMonitorService::class)->startAttempt('status-running', 'FailureTransportProbe', 'async', null, true);
        $this->entityManager->clear();

        $api = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/monitor/status', $this->createAdminUser()), 200)['data'];
        $tester = $this->command('app:monitor:status');
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        self::assertEquals($api, $this->json($tester));
        self::assertContains('status-running', array_column($api['running'], 'jobId'));
    }

    public function testTheFailureAnalyticsMatchTheApi(): void
    {
        foreach (['2026-03-15 10:05:00+00', '2026-03-15 10:20:00+00', '2026-03-15 13:00:00+00'] as $index => $createdAt) {
            $this->entityManager->getConnection()->executeStatement(
                "INSERT INTO job_monitors (id, job_id, name, status, exception_class, exception, retried, started_at, finished_at, created_at, updated_at)
                 VALUES (gen_random_uuid(), :job_id, 'AnalyticsProbe', 'failed', 'RuntimeException', '{\"message\": \"Probe failed.\"}', :retried, :created_at, :created_at, :created_at, :created_at)",
                ['job_id' => 'analytics-cli-' . $index, 'created_at' => $createdAt, 'retried' => $index === 0 ? 'true' : 'false'],
            );
        }

        $api = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/monitor/analytics/failures?from=2026-03-15T10:00:00Z&to=2026-03-15T11:00:00Z', $this->createAdminUser()),
            200,
        )['data'];
        $tester = $this->command('app:monitor:analytics');
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--section' => 'failures',
            '--from' => '2026-03-15T10:00:00Z',
            '--to' => '2026-03-15T11:00:00Z',
            '--json' => true,
        ]));

        self::assertEquals($api, $this->json($tester));
        self::assertSame(['retried' => 1, 'total' => 2], array_intersect_key($api['retryFrequency'], ['retried' => 0, 'total' => 0]));
        self::assertSame(['analytics-cli-1', 'analytics-cli-0'], array_column($api['recentFailures'], 'jobId'));
    }

    public function testThePruneDryRunCountsOnlyWhatThePruneDeletes(): void
    {
        $service = static::getContainer()->get(JobMonitorService::class);
        foreach (['prune-old', 'prune-recent'] as $jobId) {
            $service->markFinished($jobId, $service->startAttempt($jobId, 'FailureTransportProbe', 'async', null, true));
        }
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE job_monitors SET created_at = now() - INTERVAL '10 days' WHERE job_id = 'prune-old'",
        );
        $tester = $this->command('app:monitor:prune');

        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '7', '--dry-run' => true]));
        self::assertStringContainsString('Would prune 1 completed job monitor(s)', $tester->getDisplay());
        $pruned = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/monitor/prune', $this->createAdminUser(), ['days' => 7]),
            200,
        )['data'];
        self::assertSame(1, $pruned['pruned']);
        self::assertSame(Command::INVALID, $tester->execute(['--days' => '0']));
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/monitor/prune', $this->createAdminUser(), ['days' => 0]), 422);
    }

    public function testAnInlineRunIsRecordedAndListedLikeAQueuedJob(): void
    {
        $port = static::getContainer()->get(JobMonitorAdministrationInterface::class);

        $run = $port->runInline(new FailureTransportProbe('inline-ok', false));
        try {
            $port->runInline(new FailureTransportProbe('inline-broken', true));
            self::fail('The handler error must reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Probe "inline-broken" failed.', $exception->getMessage());
        }
        $this->entityManager->clear();

        $tester = $this->command('app:monitor:jobs');
        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'FailureTransportProbe', '--json' => true]));
        $jobs = array_column($this->json($tester)['items'], null, 'jobId');

        self::assertSame([], $this->async()->getSent(), 'An inline run is handled in this process.');
        self::assertCount(2, $jobs);
        self::assertArrayHasKey($run->jobId, $jobs);
        self::assertSame('finished', $jobs[$run->jobId]['status']);
        self::assertSame('FailureTransportProbe', $jobs[$run->jobId]['name']);
        self::assertNull($jobs[$run->jobId]['queue']);
        self::assertSame(1, $jobs[$run->jobId]['attempt']);
        $failed = array_values(array_filter($jobs, static fn (array $job): bool => $job['status'] === 'failed'));
        self::assertCount(1, $failed);
        self::assertSame(\RuntimeException::class, $failed[0]['exceptionClass']);
    }

    private function failedJob(string $jobId): void
    {
        $service = static::getContainer()->get(JobMonitorService::class);
        $data = static::getContainer()->get(JobMessageSerializer::class)->serialize(new Envelope(new FailureTransportProbe($jobId, false)));
        self::assertNotNull($data);
        $attempt = $service->startAttempt($jobId, 'FailureTransportProbe', 'async', $data, false);
        $service->markFailed($jobId, $attempt, new \RuntimeException('Probe failed.'));
    }

    private function async(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return array<array-key, mixed> */
    private function json(CommandTester $tester): array
    {
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
