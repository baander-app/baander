<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Interface\Console;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\DTO\BulkFetchLyricsResult;
use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use App\Lyrics\Interface\Console\LyricsCoverageCommand;
use App\Lyrics\Interface\Console\LyricsFetchCommand;
use App\Lyrics\Interface\Console\LyricsStatusCommand;
use App\Lyrics\Interface\Controller\LyricsAdminController;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** The app:lyrics:* commands reach what /api/admin/lyrics/* reaches and print the same data. */
final class LyricsCommandsTest extends TestCase
{
    private const array COVERAGE = [
        'totalTracks' => 200,
        'tracksWithLyrics' => 50,
        'tracksWithoutLyrics' => 150,
        'coveragePercentage' => 25.5,
        'bySource' => ['lrclib' => 45, 'embedded' => 5],
    ];
    private const array LATEST_RUN = [
        'jobId' => 'bulk-job-7',
        'finishedAt' => '2026-11-14T09:30:00+00:00',
        'queued' => 74,
        'found' => 61,
        'noMatch' => 9,
        'skippedNoArtist' => 2,
        'skippedNoLength' => 1,
        'rejected' => 3,
        'unavailable' => 1,
    ];
    private const array STATUS = [
        'lastSyncAt' => '2026-10-08T12:00:00+00:00',
        'recentJobs' => 12,
        'failedJobs' => 1,
        'completedJobs' => 11,
        'latestRun' => self::LATEST_RUN,
    ];

    /** @var list<object> */
    private array $dispatched = [];

    public function testCoverageJsonIsTheDataTheEndpointReturns(): void
    {
        $endpoint = $this->data($this->controller()->coverage());

        $tester = new CommandTester(new LyricsCoverageCommand($this->port()));
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        self::assertSame($endpoint, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(self::COVERAGE, $endpoint);
    }

    public function testCoverageTableShowsTheSources(): void
    {
        $tester = new CommandTester(new LyricsCoverageCommand($this->port()));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('25.50 %', $tester->getDisplay());
        self::assertStringContainsString('lrclib', $tester->getDisplay());
    }

    public function testStatusJsonIsTheDataTheEndpointReturns(): void
    {
        $endpoint = $this->data($this->controller()->syncStatus());

        $tester = new CommandTester(new LyricsStatusCommand($this->port()));
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        self::assertSame($endpoint, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(self::LATEST_RUN, $endpoint['latestRun']);
    }

    public function testStatusTableShowsTheLatestRunsTally(): void
    {
        $tester = new CommandTester(new LyricsStatusCommand($this->port()));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/Job ID\s+bulk-job-7/', $display);
        self::assertMatchesRegularExpression('/Found\s+61/', $display);
        self::assertMatchesRegularExpression('/No match\s+9/', $display);
        self::assertMatchesRegularExpression('/Skipped for a missing artist\s+2/', $display);
        self::assertMatchesRegularExpression('/Skipped for a missing length\s+1/', $display);
        self::assertMatchesRegularExpression('/Rejected\s+3/', $display);
        self::assertMatchesRegularExpression('/Unavailable\s+1/', $display);
    }

    public function testStatusSaysSoWhileNoRunIsRecorded(): void
    {
        $tester = new CommandTester(new LyricsStatusCommand($this->port(['latestRun' => null] + self::STATUS)));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('no recent run', $tester->getDisplay());
        self::assertStringNotContainsString('Skipped for a missing artist', $tester->getDisplay());
    }

    public function testTheWebAndTheCommandDispatchTheSameBulkFetchForTheSameLimit(): void
    {
        $response = $this->controller(result: new BulkFetchLyricsResult(10, 0, 0))->bulkFetch(Request::create('/', 'POST', content: '{"limit":10}'));
        self::assertSame(['jobsEnqueued' => 10, 'skippedNoArtist' => 0, 'skippedNoLength' => 0], $this->data($response));

        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, new BulkFetchLyricsResult(10, 0, 0))));
        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '10']));

        self::assertEquals([new BulkFetchLyricsCommand(limit: 10)], $this->dispatched);
        self::assertEquals($this->dispatched, $inline);
        self::assertStringContainsString('Queued 10 lyrics fetch(es)', $tester->getDisplay());
        self::assertStringContainsString('Job ID: inline-job-1', $tester->getDisplay());
    }

    public function testFetchJsonIsTheEndpointsDataWithTheJobIdOfTheInlineRun(): void
    {
        $result = new BulkFetchLyricsResult(10, 2, 1);
        $endpoint = $this->data($this->controller(result: $result)->bulkFetch(Request::create('/', 'POST', content: '{"limit":10}')));

        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, $result)));
        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '10', '--json' => true]));

        self::assertSame($endpoint + ['jobId' => 'inline-job-1'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['jobsEnqueued' => 10, 'skippedNoArtist' => 2, 'skippedNoLength' => 1], $endpoint);
    }

    public function testTheFetchPrintsTheSongsItSkippedPerCause(): void
    {
        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, new BulkFetchLyricsResult(3, 2, 1))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Queued 3 lyrics fetch(es)', $display);
        self::assertStringContainsString('Skipped 2 song(s) without an artist and 1 without a length', $display);
    }

    public function testARunThatCouldLookUpNoSongSaysWhyNothingWasQueued(): void
    {
        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, new BulkFetchLyricsResult(0, 4, 0))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Nothing was queued.', $display);
        self::assertStringContainsString('Skipped 4 song(s) without an artist and 0 without a length', $display);
        self::assertStringNotContainsString('No song needs lyrics', $display);
    }

    public function testWithoutALimitBothPathsQueueEverySongWithoutLyrics(): void
    {
        // The admin page posts no body at all.
        $response = $this->controller(result: new BulkFetchLyricsResult(150, 0, 0))->bulkFetch(Request::create('/', 'POST'));
        self::assertSame(150, $this->data($response)['jobsEnqueued']);

        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, new BulkFetchLyricsResult(150, 0, 0))));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertEquals([new BulkFetchLyricsCommand()], $this->dispatched);
        self::assertEquals($this->dispatched, $inline);
        self::assertStringContainsString('every song without lyrics', $tester->getDisplay());
    }

    public function testNothingToFetchIsReportedAsSuch(): void
    {
        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, new BulkFetchLyricsResult(0, 0, 0))));

        self::assertSame(Command::SUCCESS, $tester->execute(['--delay' => '1000']));
        self::assertEquals([new BulkFetchLyricsCommand(delayMs: 1000)], $inline);
        self::assertStringContainsString('No song needs lyrics', $tester->getDisplay());
    }

    public function testANonIntegerLimitIsInvalidOnBothPaths(): void
    {
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::never())->method('runInline');
        $tester = new CommandTester(new LyricsFetchCommand($jobs));
        self::assertSame(Command::INVALID, $tester->execute(['--limit' => 'ten']));

        $this->expectException(InvalidInputException::class);
        $this->controller()->bulkFetch(Request::create('/', 'POST', content: '{"limit":"ten"}'));
    }

    public function testTheHandlersRejectionOfALimitBelowOneExitsAsInvalidInput(): void
    {
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willThrowException(new InvalidInputException('The limit must be at least 1.'));

        $tester = new CommandTester(new LyricsFetchCommand($jobs));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('The limit must be at least 1.', $tester->getDisplay());
    }

    public function testAFetchCancelledFromTheJobMonitorFailsAndSaysSo(): void
    {
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willThrowException(JobCancelledException::forJob('inline-job-2'));

        $tester = new CommandTester(new LyricsFetchCommand($jobs));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Job "inline-job-2" has been cancelled.', $tester->getDisplay());
    }

    public function testABulkFetchCancelledWhileItQueuesIsAConflictOnTheAdminPage(): void
    {
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willThrowException(JobCancelledException::forJob('inline-job-3'));

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Job "inline-job-3" has been cancelled.');

        (new LyricsAdminController($this->port(), $jobs))->bulkFetch(Request::create('/', 'POST'));
    }

    /** @param array<string, mixed> $status */
    private function port(array $status = self::STATUS): LyricsAdminPortInterface
    {
        $port = $this->createStub(LyricsAdminPortInterface::class);
        $port->method('getCoverage')->willReturn(self::COVERAGE);
        $port->method('getSyncStatus')->willReturn($status);

        return $port;
    }

    private function controller(BulkFetchLyricsResult $result = new BulkFetchLyricsResult(0, 0, 0)): LyricsAdminController
    {
        // The admin page runs the bulk fetch as a job, as the command does.
        return new LyricsAdminController($this->port(), $this->jobs($this->dispatched, $result));
    }

    /** @param list<object> $inline */
    private function jobs(array &$inline, BulkFetchLyricsResult $result): JobMonitorAdministrationInterface
    {
        $jobs = $this->createStub(JobMonitorAdministrationInterface::class);
        $jobs->method('runInline')->willReturnCallback(static function (object $message) use (&$inline, $result): InlineJobRun {
            $inline[] = $message;

            return new InlineJobRun('inline-job-1', $result);
        });

        return $jobs;
    }

    /** @return mixed the response's `data` */
    private function data(JsonResponse $response): mixed
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
