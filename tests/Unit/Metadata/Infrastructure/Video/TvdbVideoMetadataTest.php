<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Video;

use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbAdapter;
use App\Metadata\Infrastructure\Video\TvdbVideoMetadata;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TvdbVideoMetadataTest extends TestCase
{
    /** @var list<string> */
    private array $requests = [];

    public function testSeriesSearchCarriesTheYearAndNativeProviderIdentity(): void
    {
        $provider = $this->provider([
            '/v4/search?query=Show&type=series&year=2011' => ['data' => [
                ['tvdb_id' => '10', 'name' => 'Show', 'year' => '2011', 'type' => 'series'],
            ]],
        ]);

        $candidates = $provider->search(new VideoRequest(VideoKind::Series, 'Show', year: 2011));

        self::assertIsArray($candidates);
        self::assertCount(1, $candidates);
        self::assertSame('10', $candidates[0]->id);
        self::assertSame('Show', $candidates[0]->title);
        self::assertSame(2011, $candidates[0]->year);
        self::assertSame(['tvdb' => '10'], $candidates[0]->externalIds);
    }

    public function testAbsoluteEpisode137MapsToTheAiredFifthSeasonTwelfthEpisodeAcrossPages(): void
    {
        $records = $this->records();
        $records['/v4/series/10/episodes/absolute?page=0'] = [
            'data' => ['episodes' => []],
            'links' => ['next' => 'https://api4.thetvdb.com/v4/series/10/episodes/absolute?page=1'],
        ];
        $records['/v4/series/10/episodes/absolute?page=1'] = ['data' => ['episodes' => [
            ['id' => 102, 'name' => 'Later', 'seasonNumber' => 1, 'number' => 137],
        ]]];
        $title = $this->provider($records)->identify($this->request());

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame(['aired', 'absolute', 'dvd'], array_column($title->orderings, 'id'));
        self::assertSame([0, 5], array_column($title->seasons, 'number'));
        $episode = $title->seasons[1]->episodes[0];
        self::assertSame('102', $episode->id);
        self::assertSame(12, $episode->number);
        self::assertSame(137, $episode->absoluteNumber);
        self::assertSame('2011-04-17', $episode->airDate);
        self::assertSame('Episode overview', $episode->overview);
        self::assertSame('https://artworks.thetvdb.com/banners/episodes/102.jpg', $episode->still?->path);
        self::assertContains('/v4/series/10/episodes/absolute?page=1', $this->requests);
    }

    /** @return iterable<string, array{string, int}> */
    public static function alternateOrderings(): iterable
    {
        yield 'absolute' => ['absolute', 137];
        yield 'DVD' => ['dvd', 2];
    }

    #[DataProvider('alternateOrderings')]
    public function testSelectedOrderingKeepsProviderEpisodeIdsAndAiredSpecials(string $ordering, int $number): void
    {
        $records = $this->records();
        $type = $ordering === 'absolute' ? 'absolute' : 'dvd';
        $records['/v4/series/10/episodes/' . $type . '?page=0']['data']['episodes'][] = [
            'id' => 20, 'name' => 'Special', 'seasonNumber' => 1, 'number' => 99,
        ];
        $title = $this->provider($records)->identify($this->request($ordering));

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame([0, 1], array_column($title->seasons, 'number'));
        self::assertSame('20', $title->seasons[0]->episodes[0]->id);
        self::assertSame(7, $title->seasons[0]->episodes[0]->number);
        self::assertNull($title->seasons[0]->episodes[0]->absoluteNumber);
        self::assertSame('102', $title->seasons[1]->episodes[0]->id);
        self::assertSame($number, $title->seasons[1]->episodes[0]->number);
        self::assertSame(137, $title->seasons[1]->episodes[0]->absoluteNumber);
    }

    /** @return iterable<string, array{string}> */
    public static function providerOrderings(): iterable
    {
        yield 'alternate' => ['alternate'];
        yield 'regional' => ['regional'];
        yield 'alternate DVD' => ['altdvd'];
        yield 'second alternate' => ['alttwo'];
    }

    #[DataProvider('providerOrderings')]
    public function testEachOfferedProviderOrderingKeepsItsLabelAndAiredSpecials(string $ordering): void
    {
        $records = $this->records();
        $records['/v4/series/10/extended']['data']['seasons'][] = [
            'number' => 3, 'name' => 'Regional season',
            'type' => ['type' => $ordering, 'name' => 'Provider display name'],
        ];
        $records['/v4/series/10/extended']['data']['seasons'][] = [
            'number' => 3, 'type' => ['type' => '../unrecognized', 'name' => 'Unknown ordering'],
        ];
        $records['/v4/series/10/episodes/' . $ordering . '?page=0'] = ['data' => ['episodes' => [
            ['id' => 102, 'name' => 'Later', 'seasonNumber' => 3, 'number' => 4],
        ]]];

        $title = $this->provider($records)->identify($this->request($ordering));

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame(['aired', 'absolute', 'dvd', $ordering], array_column($title->orderings, 'id'));
        self::assertSame('Provider display name', $title->orderings[3]->label);
        self::assertSame([0, 3], array_column($title->seasons, 'number'));
        self::assertSame('Specials', $title->seasons[0]->name);
        self::assertSame('20', $title->seasons[0]->episodes[0]->id);
        self::assertSame(7, $title->seasons[0]->episodes[0]->number);
        self::assertSame('102', $title->seasons[1]->episodes[0]->id);
        self::assertSame(4, $title->seasons[1]->episodes[0]->number);
        self::assertSame(137, $title->seasons[1]->episodes[0]->absoluteNumber);
        self::assertContains('/v4/series/10/episodes/' . $ordering . '?page=0', $this->requests);
    }

    public function testConflictingAbsoluteNumbersDoNotApplyAnAmbiguousMapping(): void
    {
        $records = $this->records();
        $records['/v4/series/10/episodes/absolute?page=0']['data']['episodes'][] = [
            'id' => 102, 'name' => 'Later', 'seasonNumber' => 1, 'number' => 138,
        ];

        self::assertSame(VideoProviderProblem::Unavailable, $this->provider($records)->identify($this->request()));
    }

    public function testAnUnavailableOrderingReturnsItsTitleWithoutAReplacementStructure(): void
    {
        $title = $this->provider($this->records())->identify($this->request('removed-ordering'));

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertFalse($title->hasOrdering('removed-ordering'));
        self::assertSame([], $title->seasons);
        self::assertSame(['/v4/login', '/v4/series/10/extended'], $this->requests);
    }

    public function testAiredOnlySeriesDoesNotRequestUnsupportedOrderings(): void
    {
        $records = $this->records();
        $records['/v4/series/10/extended']['data']['seasons'] = [
            ['number' => 5, 'type' => ['type' => 'official']],
        ];
        $title = $this->provider($records)->identify($this->request());

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame(['aired'], array_column($title->orderings, 'id'));
        self::assertNotContains('/v4/series/10/episodes/absolute?page=0', $this->requests);
        self::assertNotContains('/v4/series/10/episodes/dvd?page=0', $this->requests);
    }

    public function testSeriesAndEpisodeCreditsCarryPersonIdentityAndKeepDifferentJobs(): void
    {
        $records = $this->records();
        $records['/v4/series/10/extended']['data']['characters'] = [
            ['id' => 31, 'peopleId' => 41, 'peopleType' => 'Actor', 'name' => 'The lead', 'sort' => 0],
            ['id' => 32, 'peopleId' => 41, 'peopleType' => 'Director', 'sort' => 1],
        ];
        $records['/v4/episodes/102/extended']['data']['characters'] = [
            ['id' => 33, 'peopleId' => 41, 'peopleType' => 'Guest Star', 'name' => 'Visitor', 'sort' => 2],
        ];
        $records['/v4/people/41/extended'] = ['data' => [
            'id' => 41, 'name' => 'Actor Director', 'image' => '/banners/people/41.jpg',
            'remoteIds' => [
                ['id' => 'nm1234567', 'sourceName' => 'IMDB', 'type' => 999],
                ['id' => '450', 'sourceName' => 'TheMovieDB.com', 'type' => 777],
                ['id' => 'tt0944947', 'sourceName' => 'IMDB'],
                ['id' => '1399', 'sourceName' => 'TV Maze'],
            ],
        ]];
        $title = $this->provider($records)->identify($this->request());

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertCount(1, $title->cast);
        self::assertCount(1, $title->crew);
        self::assertSame(['tvdb_person' => '41', 'imdb_name' => 'nm1234567', 'tmdb_person' => '450'], $title->cast[0]->identifiers);
        self::assertSame('The lead', $title->cast[0]->characterName);
        self::assertSame('actor', $title->cast[0]->role);
        self::assertSame('director', $title->crew[0]->role);
        self::assertSame('Director', $title->crew[0]->job);
        self::assertSame('31', $title->cast[0]->providerCreditId);
        self::assertSame('https://artworks.thetvdb.com/banners/people/41.jpg', $title->cast[0]->profileUrl);
        self::assertSame('Visitor', $title->seasons[1]->episodes[0]->cast[0]->characterName);
        $personRequests = array_filter($this->requests, static fn (string $path): bool => $path === '/v4/people/41/extended');
        self::assertCount(1, $personRequests);
    }

    public function testMissingAndExplicitlyEmptyCreditsRemainDifferent(): void
    {
        $records = $this->records();
        unset($records['/v4/series/10/extended']['data']['characters']);
        $records['/v4/episodes/102/extended']['data']['characters'] = [];
        $title = $this->provider($records)->identify($this->request());

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertNull($title->cast);
        self::assertNull($title->crew);
        self::assertSame([], $title->seasons[1]->episodes[0]->cast);
        self::assertSame([], $title->seasons[1]->episodes[0]->crew);
    }

    public function testArtworkAndPortraitsOutsideThePublishedHttpsHostAreOmitted(): void
    {
        $records = $this->records();
        $records['/v4/series/10/extended']['data']['image'] = 'https://images.baander.app/poster.jpg';
        $records['/v4/episodes/102/extended']['data']['image'] = 'http://artworks.thetvdb.com/still.jpg';
        $records['/v4/series/10/extended']['data']['characters'] = [
            ['id' => 31, 'peopleId' => 41, 'peopleType' => 'Actor'],
        ];
        $records['/v4/people/41/extended'] = ['data' => [
            'id' => 41, 'name' => 'Actor', 'image' => '//images.baander.app/person.jpg',
        ]];
        $title = $this->provider($records)->identify($this->request());

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertNull($title->poster);
        self::assertNull($title->seasons[1]->episodes[0]->still);
        self::assertNull($title->cast[0]->profileUrl);
    }

    /** @return iterable<string, array{int, VideoProviderProblem}> */
    public static function failures(): iterable
    {
        yield 'outage' => [503, VideoProviderProblem::Unavailable];
        yield 'bad login' => [401, VideoProviderProblem::Unavailable];
        yield 'refused request' => [400, VideoProviderProblem::Refused];
    }

    #[DataProvider('failures')]
    public function testProviderFailuresStayDistinctFromNoMatch(int $status, VideoProviderProblem $problem): void
    {
        $records = $this->records();
        $records['/v4/series/10/extended'] = $status;
        self::assertSame($problem, $this->provider($records)->identify($this->request()));
    }

    public function testOnlyAnAbsentSeriesIsNoMatchAndAnAbsentEpisodeIsUnavailable(): void
    {
        $records = $this->records();
        $records['/v4/series/10/extended'] = 404;
        self::assertNull($this->provider($records)->identify($this->request()));
        $records = $this->records();
        $records['/v4/episodes/102/extended'] = 404;
        self::assertSame(VideoProviderProblem::Unavailable, $this->provider($records)->identify($this->request()));
    }

    public function testDisabledAndUnconfiguredProvidersMakeNoRequests(): void
    {
        $disabled = $this->provider([], disabled: true);
        self::assertSame(VideoProviderProblem::Disabled, $disabled->search($this->request()));
        self::assertSame(VideoProviderProblem::Disabled, $disabled->identify($this->request()));
        $unconfigured = $this->provider([], key: '');
        self::assertFalse($unconfigured->isConfigured());
        self::assertSame(VideoProviderProblem::NotConfigured, $unconfigured->search($this->request()));
        self::assertSame(VideoProviderProblem::NotConfigured, $unconfigured->identify($this->request()));
        self::assertSame([], $this->requests);
    }

    public function testNativeSeriesAndEpisodesExposeValidatedRemoteIdentities(): void
    {
        $records = $this->records();
        $records['/v4/series/10/extended']['data']['remoteIds'] = [
            ['id' => 'tt0944947', 'sourceName' => 'IMDB', 'type' => 2],
            ['id' => '1399', 'sourceName' => 'TheMovieDB.com', 'type' => 12],
            ['id' => 'unknown', 'sourceName' => 'EIDR', 'type' => 1],
            ['id' => '1399', 'sourceName' => 'TV Maze'],
            ['id' => 'nm1234567', 'sourceName' => 'IMDB'],
        ];
        $records['/v4/episodes/102/extended']['data']['remoteIds'] = [
            ['id' => 'tt1480055', 'sourceName' => 'IMDB', 'type' => 2],
            ['id' => '63056', 'sourceName' => 'TheMovieDB.com', 'type' => 12],
            ['id' => '-10', 'sourceName' => 'TheMovieDB.com'],
        ];

        $title = $this->provider($records)->identify($this->request());

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame(['tvdb' => '10', 'imdb' => 'tt0944947', 'tmdb' => '1399'], $title->entry->externalIds);
        self::assertSame(['tvdb' => '102', 'imdb' => 'tt1480055', 'tmdb' => '63056'], $title->seasons[1]->episodes[0]->externalIds);
    }

    /** @return iterable<string, array{string, string}> */
    public static function remoteIdentities(): iterable
    {
        yield 'TMDB numeric ID' => ['tmdb', '1399'];
        yield 'IMDb title ID' => ['imdb', 'tt0944947'];
    }

    #[DataProvider('remoteIdentities')]
    public function testRemoteBaseRowsAreVerifiedAgainstTheirSourceInExtendedRecords(string $source, string $id): void
    {
        $records = [
            '/v4/search/remoteid/' . $id => ['data' => [
                ['series' => ['id' => 10, 'name' => 'Another show']],
                ['series' => ['id' => 121361, 'name' => 'Game of Thrones']],
                ['series' => ['id' => 121361, 'name' => 'Game of Thrones']],
                ['people' => ['id' => 41, 'name' => 'Person']],
            ]],
            '/v4/series/10/extended' => ['data' => [
                'id' => 10, 'name' => 'Another show', 'remoteIds' => [
                    ['id' => '1399', 'sourceName' => 'TV Maze'],
                    ['id' => 'tt0944947', 'sourceName' => 'unknown-source'],
                    ['id' => '9', 'sourceName' => 'TheMovieDB.com'],
                ],
            ]],
            '/v4/series/121361/extended' => ['data' => [
                'id' => 121361, 'name' => 'Game of Thrones', 'firstAired' => '2011-04-17',
                'remoteIds' => [
                    ['id' => 'tt0944947', 'sourceName' => 'IMDB', 'type' => 2],
                    ['id' => '1399', 'sourceName' => 'TheMovieDB.com', 'type' => 12],
                ],
                'seasons' => [], 'characters' => [],
            ]],
            '/v4/series/121361/episodes/official?page=0' => ['data' => ['episodes' => []]],
        ];

        $title = $this->provider($records)->identify(new VideoRequest(VideoKind::Series, 'Game of Thrones', externalIds: [$source => $id]));

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame('121361', $title->entry->id);
        self::assertSame('Game of Thrones', $title->entry->title);
        self::assertSame(['tvdb' => '121361', 'imdb' => 'tt0944947', 'tmdb' => '1399'], $title->entry->externalIds);
        self::assertContains('/v4/series/10/extended', $this->requests);
        $extendedRequests = array_filter($this->requests, static fn (string $path): bool => $path === '/v4/series/121361/extended');
        self::assertCount(1, $extendedRequests);
    }

    public function testConflictingRemoteIdsDoNotSelectAnIdentity(): void
    {
        $records = $this->records();
        $records['/v4/series/10/extended']['data']['remoteIds'] = [
            ['id' => '1399', 'sourceName' => 'TheMovieDB.com'],
            ['id' => '1499', 'sourceName' => 'TheMovieDB.com'],
        ];

        self::assertSame(VideoProviderProblem::Unavailable, $this->provider($records)->identify($this->request()));
    }

    public function testANumericRemoteLookupDoesNotTrustARecordFromAnotherSource(): void
    {
        $records = $this->records();
        $records['/v4/search/remoteid/1399'] = ['data' => [
            ['series' => ['id' => 10, 'name' => 'Show']],
            ['people' => ['id' => 41, 'name' => 'Person']],
        ]];
        $records['/v4/series/10/extended']['data']['remoteIds'] = [
            ['id' => '1399', 'sourceName' => 'unverified-source'],
        ];
        $title = $this->provider($records)->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '1399']));

        self::assertNull($title);
        self::assertSame(['/v4/login', '/v4/search/remoteid/1399', '/v4/series/10/extended'], $this->requests);
    }

    public function testMoviesAreOutsideTheProvidersKinds(): void
    {
        $provider = $this->provider([]);
        $request = new VideoRequest(VideoKind::Movie, 'Movie', externalIds: ['tvdb' => '10']);
        self::assertSame([], $provider->search($request));
        self::assertNull($provider->identify($request));
        self::assertSame([], $this->requests);
    }

    private function request(string $ordering = 'aired'): VideoRequest
    {
        return new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tvdb' => '10'], ordering: $ordering);
    }

    /** @return array<string, array<string, mixed>|int> */
    private function records(): array
    {
        return [
            '/v4/series/10/extended' => ['data' => [
                'id' => 10, 'name' => 'Show', 'firstAired' => '2011-04-17', 'overview' => 'Series overview',
                'image' => '/banners/posters/10.jpg', 'originalLanguage' => 'eng', 'genres' => [['name' => 'Drama']],
                'characters' => [], 'seasons' => [
                    ['number' => 0, 'name' => 'Specials', 'type' => ['type' => 'official']],
                    ['number' => 5, 'name' => 'Season 5', 'type' => ['type' => 'official']],
                    ['number' => 1, 'type' => ['type' => 'absolute']],
                    ['number' => 1, 'type' => ['type' => 'dvd']],
                ],
            ]],
            '/v4/series/10/episodes/official?page=0' => ['data' => ['episodes' => [
                ['id' => 102, 'name' => 'Later', 'seasonNumber' => 5, 'number' => 12],
                ['id' => 20, 'name' => 'Special', 'seasonNumber' => 0, 'number' => 7],
            ]]],
            '/v4/series/10/episodes/absolute?page=0' => ['data' => ['episodes' => [
                ['id' => 102, 'name' => 'Later', 'seasonNumber' => 1, 'number' => 137],
            ]]],
            '/v4/series/10/episodes/dvd?page=0' => ['data' => ['episodes' => [
                ['id' => 102, 'name' => 'Later', 'seasonNumber' => 1, 'number' => 2],
            ]]],
            '/v4/episodes/102/extended' => ['data' => [
                'id' => 102, 'name' => 'Later', 'seasonNumber' => 5, 'number' => 12, 'aired' => '2011-04-17',
                'overview' => 'Episode overview', 'image' => '/banners/episodes/102.jpg',
            ]],
            '/v4/episodes/20/extended' => ['data' => [
                'id' => 20, 'name' => 'Special', 'seasonNumber' => 0, 'number' => 7,
            ]],
        ];
    }

    /** @param array<string, array<string, mixed>|int> $records */
    private function provider(array $records, bool $disabled = false, string $key = 'fixture-license'): TvdbVideoMetadata
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($records): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);
            $path .= $query === null ? '' : '?' . $query;
            $this->requests[] = $path;
            if ($path === '/v4/login') {
                return new MockResponse('{"data":{"token":"fixture-token"},"status":"success"}');
            }
            $record = $records[$path] ?? throw new \LogicException('No fixture for ' . $path);

            return is_int($record)
                ? new MockResponse('{}', ['http_code' => $record])
                : new MockResponse(json_encode($record + ['status' => 'success'], JSON_THROW_ON_ERROR));
        });
        $transport = new TvdbAdapter(
            $key,
            new PublicHttpClient($http, new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1'])),
            $disabled ? ProviderSwitches::withDisabled(MetadataProvider::Tvdb) : ProviderSwitches::allEnabled(),
            new ArrayAdapter(),
        );

        return new TvdbVideoMetadata($transport);
    }
}
