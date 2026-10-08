<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Console;

use App\Activity\Application\Port\ActivityAnalyticsPortInterface;
use App\Activity\Interface\Console\ActivityEngagementCommand;
use App\Activity\Interface\Console\ActivitySummaryCommand;
use App\Activity\Interface\Console\ActivityTopArtistsCommand;
use App\Activity\Interface\Console\ActivityTopTracksCommand;
use App\Activity\Interface\Controller\ActivityAdminController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** The app:activity:* commands read the port the /api/admin/activity/* endpoints read, with the same range rules. */
final class ActivityCommandsTest extends TestCase
{
    private const array SUMMARY = ['total_plays' => 42, 'unique_tracks' => 7, 'unique_artists' => 3, 'total_listening_time' => 9876];
    private const array ENGAGEMENT = ['active_users' => 4, 'avg_plays_per_user' => 10.5, 'avg_session_length' => 2469.25];
    private const array TRACKS = [
        ['track_name' => 'Blue Train', 'artist_name' => 'John Coltrane', 'album_name' => 'Blue Train', 'play_count' => 9],
        ['track_name' => 'Untitled', 'artist_name' => null, 'album_name' => null, 'play_count' => 2],
    ];
    private const array ARTISTS = [
        ['artist_name' => 'John Coltrane', 'play_count' => 9],
        ['artist_name' => 'Miles Davis', 'play_count' => 4],
    ];

    /** @return iterable<string, array{string, string, array<mixed>, bool}> */
    public static function endpoints(): iterable
    {
        yield 'summary' => ['summary', 'getSummary', self::SUMMARY, false];
        yield 'top-tracks' => ['topTracks', 'getTopTracks', self::TRACKS, true];
        yield 'top-artists' => ['topArtists', 'getTopArtists', self::ARTISTS, true];
        yield 'engagement' => ['engagement', 'getEngagement', self::ENGAGEMENT, false];
    }

    /** @param array<mixed> $result */
    #[DataProvider('endpoints')]
    public function testJsonIsTheDataTheEndpointReturnsForTheSameRange(string $action, string $method, array $result, bool $limited): void
    {
        $calls = [];
        $analytics = $this->createMock(ActivityAnalyticsPortInterface::class);
        $analytics->expects(self::exactly(2))->method($method)->willReturnCallback(
            static function (mixed ...$arguments) use (&$calls, $result): array {
                $calls[] = $arguments;

                return $result;
            },
        );
        $query = ['from' => '2026-03-01', 'to' => '2026-03-10'] + ($limited ? ['limit' => '5'] : []);

        $response = (new ActivityAdminController($analytics))->{$action}(new Request($query));
        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];

        $tester = new CommandTester(self::command($action, $analytics));
        $options = ['--json' => true];
        foreach ($query as $name => $value) {
            $options['--' . $name] = $value;
        }
        self::assertSame(Command::SUCCESS, $tester->execute($options));

        self::assertSame($data, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertEquals($calls[0], $calls[1]);
        self::assertEquals(new \DateTimeImmutable('2026-03-01 00:00:00'), $calls[1][0]);
        self::assertEquals(new \DateTimeImmutable('2026-03-11 00:00:00'), $calls[1][1], 'The last day is counted up to the start of the next day.');
        if ($limited) {
            self::assertSame(5, $calls[1][2]);
        }
    }

    /** @param array<mixed> $result */
    #[DataProvider('endpoints')]
    public function testWithoutOptionsTheRangeAndLimitAreTheEndpointDefaults(string $action, string $method, array $result, bool $limited): void
    {
        $calls = [];
        $analytics = $this->createStub(ActivityAnalyticsPortInterface::class);
        $analytics->method($method)->willReturnCallback(
            static function (mixed ...$arguments) use (&$calls, $result): array {
                $calls[] = $arguments;

                return $result;
            },
        );

        (new ActivityAdminController($analytics))->{$action}(new Request());
        self::assertSame(Command::SUCCESS, (new CommandTester(self::command($action, $analytics)))->execute([]));

        self::assertEqualsWithDelta($calls[0][0]->getTimestamp(), $calls[1][0]->getTimestamp(), 5);
        self::assertEqualsWithDelta((new \DateTimeImmutable('-30 days'))->getTimestamp(), $calls[1][0]->getTimestamp(), 5);
        self::assertEquals(new \DateTimeImmutable('tomorrow'), $calls[1][1]);
        if ($limited) {
            self::assertSame(10, $calls[1][2]);
        }
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function invalidOptions(): iterable
    {
        foreach (['summary', 'topTracks', 'topArtists', 'engagement'] as $action) {
            yield $action . ' impossible date' => [$action, ['--from' => '2026-02-31'], '--from'];
            yield $action . ' not a date' => [$action, ['--to' => 'tomorrow'], '--to'];
            yield $action . ' last day before first' => [$action, ['--from' => '2026-04-02', '--to' => '2026-04-01'], '--to'];
        }
        foreach (['topTracks', 'topArtists'] as $action) {
            yield $action . ' limit 0' => [$action, ['--limit' => '0'], '--limit'];
            yield $action . ' limit 101' => [$action, ['--limit' => '101'], '--limit'];
            yield $action . ' limit abc' => [$action, ['--limit' => 'abc'], '--limit'];
        }
    }

    /** @param array<string, string> $options */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsExitInvalidWithoutReading(string $action, array $options, string $named): void
    {
        $analytics = $this->createMock(ActivityAnalyticsPortInterface::class);
        $analytics->expects(self::never())->method(self::anything());

        $tester = new CommandTester(self::command($action, $analytics));

        self::assertSame(Command::INVALID, $tester->execute($options));
        self::assertStringContainsString($named . ':', $tester->getDisplay());
    }

    public function testWithNoActivityTheCommandsPrintAnEmptyResultAndSucceed(): void
    {
        $analytics = $this->createStub(ActivityAnalyticsPortInterface::class);
        $analytics->method('getSummary')->willReturn(['total_plays' => 0, 'unique_tracks' => 0, 'unique_artists' => 0, 'total_listening_time' => 0]);
        $analytics->method('getEngagement')->willReturn(['active_users' => 0, 'avg_plays_per_user' => 0.0, 'avg_session_length' => 0.0]);
        $analytics->method('getTopTracks')->willReturn([]);
        $analytics->method('getTopArtists')->willReturn([]);
        $range = ['--from' => '2020-01-01', '--to' => '2020-01-31'];

        $tracks = new CommandTester(new ActivityTopTracksCommand($analytics));
        self::assertSame(Command::SUCCESS, $tracks->execute($range));
        self::assertStringContainsString('No tracks were played in this range.', $tracks->getDisplay());

        $artists = new CommandTester(new ActivityTopArtistsCommand($analytics));
        self::assertSame(Command::SUCCESS, $artists->execute($range));
        self::assertStringContainsString('No artists were played in this range.', $artists->getDisplay());

        $json = new CommandTester(new ActivityTopTracksCommand($analytics));
        self::assertSame(Command::SUCCESS, $json->execute($range + ['--json' => true]));
        self::assertSame([], json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR));

        $summary = new CommandTester(new ActivitySummaryCommand($analytics));
        self::assertSame(Command::SUCCESS, $summary->execute($range));
        self::assertMatchesRegularExpression('/Total plays\s+0\s/', $summary->getDisplay());

        $engagement = new CommandTester(new ActivityEngagementCommand($analytics));
        self::assertSame(Command::SUCCESS, $engagement->execute($range));
        self::assertMatchesRegularExpression('/Active users\s+0\s/', $engagement->getDisplay());
    }

    public function testTablesShowTheRows(): void
    {
        $analytics = $this->createStub(ActivityAnalyticsPortInterface::class);
        $analytics->method('getTopTracks')->willReturn(self::TRACKS);

        $tester = new CommandTester(new ActivityTopTracksCommand($analytics));

        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => '2026-03-01', '--to' => '2026-03-10']));
        self::assertMatchesRegularExpression('/Blue Train\s+John Coltrane\s+Blue Train\s+9\s.*\n.*Untitled\s+-\s+-\s+2\s/', $tester->getDisplay());
    }

    private static function command(string $action, ActivityAnalyticsPortInterface $analytics): Command
    {
        return match ($action) {
            'summary' => new ActivitySummaryCommand($analytics),
            'topTracks' => new ActivityTopTracksCommand($analytics),
            'topArtists' => new ActivityTopArtistsCommand($analytics),
            'engagement' => new ActivityEngagementCommand($analytics),
            default => throw new \LogicException(sprintf('No command for "%s".', $action)),
        };
    }
}
