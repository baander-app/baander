<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\DTO\JobAnalyticsRange;
use App\Shared\Application\DTO\JobMonitorOverview;
use App\Shared\Application\DTO\JobMonitorPage;
use App\Shared\Application\DTO\JobMonitorPruneResult;
use App\Shared\Application\DTO\JobMonitorQuery;
use App\Shared\Application\DTO\JobMonitorRecord;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Interface\Console\MonitorAnalyticsCommand;
use App\Shared\Interface\Console\MonitorJobCancelCommand;
use App\Shared\Interface\Console\MonitorJobRetryCommand;
use App\Shared\Interface\Console\MonitorJobShowCommand;
use App\Shared\Interface\Console\MonitorJobsCommand;
use App\Shared\Interface\Console\MonitorStatusCommand;
use App\Shared\Interface\Console\PruneJobMonitorsCommand;
use App\Shared\Interface\Controller\JobMonitorController;
use App\Shared\Interface\Resource\JobMonitorResource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class MonitorCommandsTest extends TestCase
{
    private FakeJobMonitor $monitor;

    protected function setUp(): void
    {
        $this->monitor = new FakeJobMonitor();
    }

    public function testStatusPrintsTheApiPayloadAsJson(): void
    {
        $this->monitor->overview = new JobMonitorOverview(['running' => 1, 'failed' => 2], [self::record('job-running', JobStatus::Running)]);
        $tester = new CommandTester(new MonitorStatusCommand($this->monitor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame(
            JobMonitorResource::overview($this->monitor->overview),
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testStatusTableShowsCountsAndRunningJobs(): void
    {
        $this->monitor->overview = new JobMonitorOverview(['running' => 1], [self::record('job-running', JobStatus::Running)]);
        $tester = new CommandTester(new MonitorStatusCommand($this->monitor));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('job-running', $tester->getDisplay());
        self::assertStringContainsString('ExtractAlbumCoverCommand', $tester->getDisplay());
    }

    public function testJobsPassesTheApiFiltersAndPrintsTheApiPage(): void
    {
        $this->monitor->page = new JobMonitorPage([self::record('job-failed', JobStatus::Failed)], 'next-page', true, 5);
        $tester = new CommandTester(new MonitorJobsCommand($this->monitor));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--status' => 'failed',
            '--type' => 'Extract',
            '--queue' => 'async',
            '--sort' => 'finishedAt',
            '--direction' => 'asc',
            '--limit' => '5',
            '--cursor' => 'this-page',
            '--json' => true,
        ]));

        self::assertEquals(new JobMonitorQuery('failed', 'Extract', 'async', 'finishedAt', 'asc', 5, 'this-page'), $this->monitor->query);
        self::assertSame(
            JobMonitorResource::page($this->monitor->page),
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testJobsTablePrintsTheCursorThatContinuesTheListing(): void
    {
        $this->monitor->page = new JobMonitorPage([self::record('job-failed', JobStatus::Failed)], 'next-page', true, 1);
        $tester = new CommandTester(new MonitorJobsCommand($this->monitor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '1']));
        self::assertStringContainsString('job-failed', $tester->getDisplay());
        self::assertStringContainsString('--cursor=next-page', $tester->getDisplay());
    }

    public function testJobsRejectsAnUnknownStatusAsInvalid(): void
    {
        $this->monitor->failure = new InvalidInputException('status must be one of: queued, running, finished, failed, cancelled.');
        $tester = new CommandTester(new MonitorJobsCommand($this->monitor));

        self::assertSame(Command::INVALID, $tester->execute(['--status' => 'stuck']));
    }

    public function testJobsRejectsALimitThatIsNotAnIntegerAsInvalid(): void
    {
        $tester = new CommandTester(new MonitorJobsCommand($this->monitor));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => 'abc']));
        self::assertNull($this->monitor->query);
    }

    public function testShowPrintsTheApiDetailAsJson(): void
    {
        $tester = new CommandTester(new MonitorJobShowCommand($this->monitor));

        self::assertSame(Command::SUCCESS, $tester->execute(['jobId' => 'job-failed', '--json' => true]));
        self::assertJsonStringEqualsJsonString(
            json_encode(JobMonitorResource::detail(self::record('job-failed', JobStatus::Failed)), JSON_THROW_ON_ERROR),
            $tester->getDisplay(),
        );
    }

    public function testShowOfAnUnknownJobFails(): void
    {
        $this->monitor->failure = new NotFoundException('Job not found.');
        $tester = new CommandTester(new MonitorJobShowCommand($this->monitor));

        self::assertSame(Command::FAILURE, $tester->execute(['jobId' => 'missing']));
        self::assertStringContainsString('Job not found.', $tester->getDisplay());
    }

    public function testRetryActsAsTheCliActorAndPrintsTheNewJobId(): void
    {
        $tester = new CommandTester(new MonitorJobRetryCommand($this->monitor));

        self::assertSame(Command::SUCCESS, $tester->execute(['jobId' => 'job-failed']));
        self::assertSame(['job-failed', 'cli'], $this->monitor->retried);
        self::assertStringContainsString('new-job', $tester->getDisplay());
    }

    public function testRetryOfAJobThatCannotBeRetriedFails(): void
    {
        $this->monitor->failure = new ConflictException('Only failed jobs can be retried.');
        $tester = new CommandTester(new MonitorJobRetryCommand($this->monitor));

        self::assertSame(Command::FAILURE, $tester->execute(['jobId' => 'job-running']));
        self::assertStringContainsString('Only failed jobs can be retried.', $tester->getDisplay());
    }

    public function testCancelRequestsCancellationAndAConflictFails(): void
    {
        $tester = new CommandTester(new MonitorJobCancelCommand($this->monitor));
        self::assertSame(Command::SUCCESS, $tester->execute(['jobId' => 'job-running']));
        self::assertSame('job-running', $this->monitor->cancelled);

        $this->monitor->failure = new ConflictException('Finished jobs cannot be cancelled.');
        self::assertSame(Command::FAILURE, $tester->execute(['jobId' => 'job-finished']));
        self::assertStringContainsString('Finished jobs cannot be cancelled.', $tester->getDisplay());
    }

    public function testRetryAndCancelJsonPrintTheDataTheEndpointsReturn(): void
    {
        $controller = new JobMonitorController($this->monitor);

        $retry = new CommandTester(new MonitorJobRetryCommand($this->monitor));
        self::assertSame(Command::SUCCESS, $retry->execute(['jobId' => 'job-failed', '--json' => true]));
        self::assertSame(
            self::data($controller->retry('job-failed', new InMemoryUser('admin@baander.app', null))),
            json_decode($retry->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );

        $cancel = new CommandTester(new MonitorJobCancelCommand($this->monitor));
        self::assertSame(Command::SUCCESS, $cancel->execute(['jobId' => 'job-running', '--json' => true]));
        self::assertSame(self::data($controller->cancel('job-running')), json_decode($cancel->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testPruneJsonHasTheFieldsOfTheEndpointsData(): void
    {
        $endpoint = self::data((new JobMonitorController($this->monitor))->prune(Request::create('/', 'POST', content: '{"days":3}')));

        $tester = new CommandTester(new PruneJobMonitorsCommand($this->monitor));
        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '3', '--json' => true]));
        $printed = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(array_keys($endpoint), array_keys($printed));
        self::assertSame(4, $printed['pruned']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $printed['olderThan']));
    }

    public function testAnalyticsPrintsTheChosenSectionForTheGivenRange(): void
    {
        $tester = new CommandTester(new MonitorAnalyticsCommand($this->monitor));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--section' => 'failures',
            '--from' => '2026-03-15T10:00:00Z',
            '--to' => '2026-03-15T14:00:00+02:00',
            '--limit' => '7',
            '--json' => true,
        ]));

        self::assertSame(FakeJobMonitor::FAILURES, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertNotNull($this->monitor->range);
        self::assertSame('2026-03-15T10:00:00+00:00', $this->monitor->range->from->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-03-15T14:00:00+02:00', $this->monitor->range->to->format(\DateTimeInterface::ATOM));
        self::assertSame(7, $this->monitor->limit);
    }

    public function testAnalyticsTablesRenderEverySection(): void
    {
        $tester = new CommandTester(new MonitorAnalyticsCommand($this->monitor));

        foreach (['summary' => 'Success rate', 'timing' => 'Queue latency', 'failures' => 'Recent failures'] as $section => $heading) {
            self::assertSame(Command::SUCCESS, $tester->execute(['--section' => $section]), $section);
            self::assertStringContainsString($heading, $tester->getDisplay(), $section);
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidAnalyticsInput(): iterable
    {
        yield 'unknown section' => [['--section' => 'everything']];
        yield 'from without a timezone' => [['--from' => '2026-03-15T10:00:00']];
        yield 'empty range' => [['--from' => '2026-03-15T10:00:00Z', '--to' => '2026-03-15T10:00:00Z']];
        yield 'limit that is not an integer' => [['--section' => 'failures', '--limit' => 'abc']];
    }

    /** @param array<string, string> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAnalyticsInput')]
    public function testInvalidAnalyticsInputIsInvalid(array $input): void
    {
        $tester = new CommandTester(new MonitorAnalyticsCommand($this->monitor));

        self::assertSame(Command::INVALID, $tester->execute($input));
        self::assertNull($this->monitor->range);
    }

    public function testPruneDryRunCountsAndAnInvalidAgeIsInvalid(): void
    {
        $tester = new CommandTester(new PruneJobMonitorsCommand($this->monitor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '3', '--dry-run' => true]));
        self::assertSame([3, true], $this->monitor->pruned);
        self::assertStringContainsString('Would prune 4 completed job monitor(s)', $tester->getDisplay());

        $this->monitor->failure = new InvalidInputException('Days must be at least 1.');
        self::assertSame(Command::INVALID, $tester->execute(['--days' => '0']));
    }

    /** @return array<string, mixed> the response's `data` */
    private static function data(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    public static function record(string $jobId, JobStatus $status): JobMonitorRecord
    {
        $at = new \DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $failed = $status === JobStatus::Failed;

        return new JobMonitorRecord(
            jobId: $jobId,
            name: 'ExtractAlbumCoverCommand',
            queue: 'async',
            status: $status,
            progress: null,
            attempt: 1,
            retried: false,
            startedAt: $at,
            finishedAt: $status === JobStatus::Running ? null : $at->modify('+90 seconds'),
            createdAt: $at,
            updatedAt: $at,
            exceptionClass: $failed ? \RuntimeException::class : null,
            exception: $failed ? ['message' => 'No cover.', 'file' => 'Handler.php', 'line' => 12] : null,
            data: '{"type":"metadata.extract_album_cover"}',
            dataTruncated: false,
            durationMicroseconds: $status === JobStatus::Running ? null : 90_000_000,
        );
    }
}

/** Records what the commands ask of the job monitor. */
final class FakeJobMonitor implements JobMonitorAdministrationInterface
{
    public const array FAILURES = [
        'topFailingTypes' => [['name' => 'ExtractAlbumCoverCommand', 'count' => 2]],
        'topExceptionClasses' => [['class' => 'RuntimeException', 'count' => 2]],
        'retryFrequency' => ['retried' => 1, 'total' => 2, 'rate' => 0.5],
        'recentFailures' => [['jobId' => 'job-failed', 'name' => 'ExtractAlbumCoverCommand', 'exceptionClass' => 'RuntimeException', 'exceptionMessage' => 'No cover.', 'failedAt' => '2026-03-15T10:01:30+00:00']],
    ];

    public JobMonitorOverview $overview;
    public JobMonitorPage $page;
    public ?\Throwable $failure = null;
    public ?JobMonitorQuery $query = null;
    public ?JobAnalyticsRange $range = null;
    public ?int $limit = null;
    /** @var array{string, string}|null */
    public ?array $retried = null;
    public ?string $cancelled = null;
    /** @var array{int, bool}|null */
    public ?array $pruned = null;

    public function __construct()
    {
        $this->overview = new JobMonitorOverview([], []);
        $this->page = new JobMonitorPage([], null, false, 50);
    }

    public function overview(): JobMonitorOverview
    {
        $this->fail();

        return $this->overview;
    }

    public function jobs(JobMonitorQuery $query): JobMonitorPage
    {
        $this->fail();
        $this->query = $query;

        return $this->page;
    }

    public function job(string $jobId): JobMonitorRecord
    {
        $this->fail();

        return MonitorCommandsTest::record($jobId, JobStatus::Failed);
    }

    public function retry(string $jobId, string $actor): string
    {
        $this->fail();
        $this->retried = [$jobId, $actor];

        return 'new-job';
    }

    public function cancel(string $jobId): void
    {
        $this->fail();
        $this->cancelled = $jobId;
    }

    public function prune(int $days, bool $dryRun = false): JobMonitorPruneResult
    {
        $this->fail();
        $this->pruned = [$days, $dryRun];

        return new JobMonitorPruneResult(4, new \DateTimeImmutable('-3 days'));
    }

    public function analyticsSummary(JobAnalyticsRange $range): array
    {
        $this->range = $range;

        return ['statusCounts' => ['finished' => 3, 'failed' => 1], 'jobTypeBreakdown' => [['name' => 'ExtractAlbumCoverCommand', 'count' => 4]], 'successRate' => 0.75, 'throughputPerHour' => 0.2];
    }

    public function analyticsTiming(JobAnalyticsRange $range): array
    {
        $this->range = $range;

        return ['executionTimes' => [['name' => 'ExtractAlbumCoverCommand', 'avg' => 1.5, 'median' => 1.2, 'p95' => 3.0]], 'queueLatency' => [['name' => 'ExtractAlbumCoverCommand', 'avg' => 0.1]]];
    }

    public function analyticsFailures(JobAnalyticsRange $range, int $limit = 50): array
    {
        $this->range = $range;
        $this->limit = $limit;

        return self::FAILURES;
    }

    public function runInline(object $message): InlineJobRun
    {
        return new InlineJobRun('inline-job', null);
    }

    private function fail(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
