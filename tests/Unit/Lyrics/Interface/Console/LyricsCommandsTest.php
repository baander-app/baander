<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Interface\Console;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use App\Lyrics\Interface\Console\LyricsCoverageCommand;
use App\Lyrics\Interface\Console\LyricsFetchCommand;
use App\Lyrics\Interface\Console\LyricsStatusCommand;
use App\Lyrics\Interface\Controller\LyricsAdminController;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

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
    private const array STATUS = [
        'lastSyncAt' => '2026-10-08T12:00:00+00:00',
        'recentJobs' => 12,
        'failedJobs' => 1,
        'completedJobs' => 11,
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
    }

    public function testTheWebAndTheCommandDispatchTheSameBulkFetchForTheSameLimit(): void
    {
        $response = $this->controller(result: 10)->bulkFetch(Request::create('/', 'POST', content: '{"limit":10}'));
        self::assertSame(['jobsEnqueued' => 10], $this->data($response));

        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, 10)));
        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '10']));

        self::assertEquals([new BulkFetchLyricsCommand(limit: 10)], $this->dispatched);
        self::assertEquals($this->dispatched, $inline);
        self::assertStringContainsString('Queued 10 lyrics fetch(es)', $tester->getDisplay());
        self::assertStringContainsString('Job ID: inline-job-1', $tester->getDisplay());
    }

    public function testWithoutALimitBothPathsQueueEverySongWithoutLyrics(): void
    {
        // The admin page posts no body at all.
        $response = $this->controller(result: 150)->bulkFetch(Request::create('/', 'POST'));
        self::assertSame(['jobsEnqueued' => 150], $this->data($response));

        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, 150)));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertEquals([new BulkFetchLyricsCommand()], $this->dispatched);
        self::assertEquals($this->dispatched, $inline);
        self::assertStringContainsString('every song without lyrics', $tester->getDisplay());
    }

    public function testNothingToFetchIsReportedAsSuch(): void
    {
        $inline = [];
        $tester = new CommandTester(new LyricsFetchCommand($this->jobs($inline, 0)));

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

    private function port(): LyricsAdminPortInterface
    {
        $port = $this->createStub(LyricsAdminPortInterface::class);
        $port->method('getCoverage')->willReturn(self::COVERAGE);
        $port->method('getSyncStatus')->willReturn(self::STATUS);

        return $port;
    }

    private function controller(int $result = 0): LyricsAdminController
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($result): Envelope {
            $this->dispatched[] = $message;

            return (new Envelope($message))->with(new HandledStamp($result, 'handler'));
        });

        return new LyricsAdminController($this->port(), $bus);
    }

    /** @param list<object> $inline */
    private function jobs(array &$inline, int $result): JobMonitorAdministrationInterface
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
