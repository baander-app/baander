<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Video;

use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Metadata\Infrastructure\Video\TmdbVideoMetadata;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class TmdbVideoMetadataTest extends TestCase
{
    /** @var list<string> */
    private array $requests = [];

    public function testSeriesSearchUsesFirstAirYearAndReturnsCandidates(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('2011', $query['first_air_date_year']);
            self::assertSame('Show', $query['query']);

            return new MockResponse('{"results":[{"id":10,"name":"Show","first_air_date":"2011-04-17"}]}');
        });
        $result = $this->provider($http)->search(new VideoRequest(VideoKind::Series, 'Show', year: 2011));
        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('10', $result[0]->id);
        self::assertSame('Show', $result[0]->title);
        self::assertSame(2011, $result[0]->year);
    }

    public function testAiredStructureKeepsCanonicalAbsoluteNumbersAndOffersOnlySupportedGroups(): void
    {
        $provider = $this->provider($this->http($this->seriesRecords()));
        $title = $provider->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10']));
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame(['aired', 'dvd', 'absolute'], array_column($title->orderings, 'kind'));
        self::assertSame([5], array_column($title->seasons, 'number'));
        self::assertSame(['100', '102'], array_column($title->seasons[0]->episodes, 'id'));
        self::assertSame([1, 12], array_column($title->seasons[0]->episodes, 'number'));
        self::assertSame([1, 2], array_column($title->seasons[0]->episodes, 'absoluteNumber'));
        self::assertSame('2011-04-17', $title->seasons[0]->episodes[0]->airDate);
    }

    public function testSelectedAbsoluteAndDvdGroupsUseGroupThenEpisodeOrder(): void
    {
        $provider = $this->provider($this->http($this->seriesRecords()));
        $absolute = $provider->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10'], ordering: 'absolute-group'));
        self::assertInstanceOf(VideoTitle::class, $absolute);
        self::assertSame([1, 2], array_column($absolute->seasons, 'number'));
        self::assertSame('100', $absolute->seasons[0]->episodes[0]->id);
        self::assertSame('102', $absolute->seasons[1]->episodes[0]->id);
        self::assertSame(2, $absolute->seasons[1]->episodes[0]->absoluteNumber);
        $dvd = $provider->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10'], ordering: 'dvd-group'));
        self::assertInstanceOf(VideoTitle::class, $dvd);
        self::assertSame(['102', '100'], array_column($dvd->seasons[0]->episodes, 'id'));
        self::assertSame([1, 2], array_column($dvd->seasons[0]->episodes, 'number'));
        self::assertSame([2, 1], array_column($dvd->seasons[0]->episodes, 'absoluteNumber'));
    }

    /** @return iterable<string, array{string}> */
    public static function alternateOrderings(): iterable
    {
        yield 'absolute' => ['absolute-group'];
        yield 'DVD' => ['dvd-group'];
    }

    #[DataProvider('alternateOrderings')]
    public function testAlternateOrderingKeepsMixedAndOmittedSpecialsInSeasonZero(string $ordering): void
    {
        $records = $this->seriesRecords();
        $special = ['id' => 20, 'name' => 'Included special', 'season_number' => 0, 'episode_number' => 7, 'order' => 0];
        $omitted = ['id' => 21, 'name' => 'Omitted special', 'season_number' => 0, 'episode_number' => 8];
        $records['/3/tv/10']['seasons'] = [['season_number' => 0], ['season_number' => 5]];
        $records['/3/tv/10/season/0'] = ['season_number' => 0, 'name' => 'Specials', 'episodes' => [$omitted, $special]];
        $records['/3/tv/episode_group/dvd-group']['groups'][0]['episodes'] = [
            $special,
            ['id' => 100, 'name' => 'First', 'season_number' => 5, 'episode_number' => 1, 'order' => 1],
            ['id' => 102, 'name' => 'Later', 'season_number' => 5, 'episode_number' => 12, 'order' => 2],
        ];
        $records['/3/tv/episode_group/absolute-group']['groups'][1]['episodes'][] = array_replace($special, ['order' => 1]);
        $title = $this->provider($this->http($records))->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10'], ordering: $ordering));
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame(0, $title->seasons[0]->number);
        self::assertSame(['20', '21'], array_column($title->seasons[0]->episodes, 'id'));
        self::assertSame([7, 8], array_column($title->seasons[0]->episodes, 'number'));
        $regular = array_slice($title->seasons, 1);
        foreach ($regular as $season) {
            self::assertNotContains('20', array_column($season->episodes, 'id'));
            self::assertNotContains('21', array_column($season->episodes, 'id'));
        }
        if ($ordering === 'dvd-group') {
            self::assertSame([2, 3], array_column($regular[0]->episodes, 'number'));
        }
    }

    public function testDisappearedOrderingReturnsIdentifiedTitleWithoutAReplacementStructure(): void
    {
        $http = $this->http($this->seriesRecords());
        $title = $this->provider($http)->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10'], ordering: 'removed-group'));
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame('10', $title->entry->id);
        self::assertSame(['aired', 'dvd-group', 'absolute-group'], array_column($title->orderings, 'id'));
        self::assertSame([], $title->seasons);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testSeriesWithoutGroupsOffersOnlyAiredAndFindResolvesTheTvdbId(): void
    {
        $records = $this->seriesRecords();
        $records['/3/find/777'] = ['tv_results' => [['id' => 10, 'name' => 'Show']]];
        $records['/3/tv/10']['episode_groups'] = ['results' => []];
        $records['/3/tv/10']['external_ids'] = ['tvdb_id' => 777, 'imdb_id' => 'tt123'];
        $http = $this->http($records);
        $title = $this->provider($http)->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tvdb' => '777']));
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame(['aired'], array_column($title->orderings, 'id'));
        self::assertSame(['tmdb' => '10', 'imdb' => 'tt123', 'tvdb' => '777'], $title->entry->externalIds);
        self::assertNull($title->seasons[0]->episodes[0]->absoluteNumber);
    }

    public function testMovieFieldsAndCreditsPreserveEveryReturnedPersonIdentifier(): void
    {
        $records = ['/3/movie/12' => [
            'id' => 12, 'title' => 'Film', 'release_date' => '1995-12-15',
            'overview' => 'Plot', 'poster_path' => '/poster.jpg', 'backdrop_path' => '/backdrop.jpg',
            'tagline' => 'A tagline', 'runtime' => 170, 'vote_average' => 8.2, 'original_language' => 'en',
            'genres' => [['id' => 80, 'name' => 'Crime']], 'imdb_id' => 'tt123',
            'belongs_to_collection' => ['id' => 21, 'name' => 'Collection'],
            'credits' => [
                'cast' => [['id' => 42, 'name' => 'Actor', 'character' => 'Detective', 'order' => 0, 'credit_id' => 'cast-credit', 'imdb_id' => 'nm123']],
                'crew' => [['id' => 43, 'name' => 'Director', 'job' => 'Director', 'department' => 'Directing', 'credit_id' => 'crew-credit']],
            ],
        ]];
        $title = $this->provider($this->http($records))->identify(new VideoRequest(VideoKind::Movie, 'Film', externalIds: ['tmdb' => '12']));
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame(1995, $title->entry->year);
        self::assertSame('Plot', $title->overview);
        self::assertSame('https://image.tmdb.org/t/p/original/poster.jpg', $title->poster?->path);
        self::assertSame('A tagline', $title->tagline);
        self::assertSame(170, $title->runtime);
        self::assertSame(8.2, $title->rating);
        self::assertSame('en', $title->originalLanguage);
        self::assertSame(21, $title->collectionId);
        self::assertSame('Collection', $title->collectionName);
        self::assertSame(['Crime'], $title->genres);
        self::assertSame(['tmdb_person' => '42', 'imdb_name' => 'nm123'], $title->cast[0]->identifiers);
        self::assertSame('Detective', $title->cast[0]->characterName);
        self::assertSame('cast-credit', $title->cast[0]->providerCreditId);
        self::assertSame('director', $title->crew[0]->role);
    }

    public function testMalformedCreditPartitionIsUnavailableWhileAnEmptyPartitionIsAuthoritative(): void
    {
        $records = ['/3/movie/12' => [
            'id' => 12, 'title' => 'Film',
            'credits' => ['cast' => [['name' => 'Unidentified actor']], 'crew' => []],
        ]];
        $title = $this->provider($this->http($records))->identify(new VideoRequest(VideoKind::Movie, 'Film', externalIds: ['tmdb' => '12']));
        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertNull($title->cast);
        self::assertSame([], $title->crew);
    }

    /** @return iterable<string, array{int, string, VideoProviderProblem|null}> */
    public static function outcomes(): iterable
    {
        yield 'outage' => [503, '{}', VideoProviderProblem::Unavailable];
        yield 'authentication' => [401, '{}', VideoProviderProblem::Unavailable];
        yield 'forbidden credential' => [403, '{}', VideoProviderProblem::Unavailable];
        yield 'refused title' => [422, '{}', VideoProviderProblem::Refused];
        yield 'not found' => [404, '{}', null];
        yield 'malformed json' => [200, '{broken', VideoProviderProblem::Unavailable];
        yield 'missing identifier' => [200, '{"title":"Film"}', VideoProviderProblem::Unavailable];
        yield 'empty response' => [200, '{}', VideoProviderProblem::Unavailable];
    }

    #[DataProvider('outcomes')]
    public function testIdentifyDistinguishesFailuresFromAnAbsentTitle(int $status, string $body, ?VideoProviderProblem $expected): void
    {
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
        self::assertSame($expected, $this->provider($http)->identify(new VideoRequest(VideoKind::Movie, 'Film', externalIds: ['tmdb' => '12'])));
    }

    public function testEmptySearchAndMalformedSearchHaveDifferentOutcomes(): void
    {
        $http = new MockHttpClient([new MockResponse('{"results":[]}'), new MockResponse('{}')]);
        $provider = $this->provider($http);
        $request = new VideoRequest(VideoKind::Series, 'Show');
        self::assertSame([], $provider->search($request));
        self::assertSame(VideoProviderProblem::Unavailable, $provider->search($request));
    }

    public function testDisabledAndUnconfiguredProvidersMakeNoRequest(): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('An unusable provider must make no request.'); });
        $request = new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10']);
        self::assertSame(VideoProviderProblem::Disabled, $this->provider($http, enabled: false)->identify($request));
        self::assertSame(VideoProviderProblem::Disabled, $this->provider($http, enabled: false)->search($request));
        self::assertSame(VideoProviderProblem::NotConfigured, $this->provider($http, token: '')->identify($request));
        self::assertSame(VideoProviderProblem::NotConfigured, $this->provider($http, token: '')->search($request));
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testMissingSeasonCannotApplyAPartialSeriesStructure(): void
    {
        $records = $this->seriesRecords();
        $records['/3/tv/10/season/5'] = new MockResponse('{}', ['http_code' => 404]);
        self::assertSame(VideoProviderProblem::Unavailable, $this->provider($this->http($records))->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10'])));
    }

    public function testMissingAbsoluteGroupCannotApplyAnAiredStructureWithoutItsNumbers(): void
    {
        $records = $this->seriesRecords();
        $records['/3/tv/episode_group/absolute-group'] = new MockResponse('{}', ['http_code' => 404]);
        self::assertSame(VideoProviderProblem::Unavailable, $this->provider($this->http($records))->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10'])));
    }

    public function testMissingAiredSpecialSeasonCannotApplyAPartialAlternateOrdering(): void
    {
        $records = $this->seriesRecords();
        $records['/3/tv/10']['seasons'][] = ['season_number' => 0];
        $records['/3/tv/10/season/0'] = new MockResponse('{}', ['http_code' => 404]);
        self::assertSame(VideoProviderProblem::Unavailable, $this->provider($this->http($records))->identify(new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tmdb' => '10'], ordering: 'dvd-group')));
    }

    public function testASeriesWithNoTvdbCounterpartUsesItsSuppliedImdbId(): void
    {
        $records = $this->seriesRecords();
        $records['/3/find/777'] = ['tv_results' => []];
        $records['/3/find/tt123'] = ['tv_results' => [['id' => 10, 'name' => 'Show']]];
        $records['/3/tv/10']['external_ids'] = ['tvdb_id' => 777, 'imdb_id' => 'tt123'];
        $request = new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tvdb' => '777', 'imdb' => 'tt123']);

        $title = $this->provider($this->http($records))->identify($request);

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame('10', $title->entry->id);
        self::assertSame(['/3/find/777', '/3/find/tt123', '/3/tv/10'], array_slice($this->requests, 0, 3));
    }

    /** @return iterable<string, array{array<string, mixed>|MockResponse, VideoProviderProblem|null}> */
    public static function tvdbFindFailures(): iterable
    {
        yield 'outage' => [new MockResponse('{}', ['http_code' => 503]), VideoProviderProblem::Unavailable];
        yield 'refused request' => [new MockResponse('{}', ['http_code' => 400]), VideoProviderProblem::Refused];
        yield 'malformed result' => [['tv_results' => ['malformed' => true]], VideoProviderProblem::Unavailable];
        yield 'ambiguous result' => [['tv_results' => [['id' => 10], ['id' => 11]]], null];
    }

    /** @param array<string, mixed>|MockResponse $failure */
    #[DataProvider('tvdbFindFailures')]
    public function testATvdbLookupFailureOrAmbiguityDoesNotFallThroughToImdb(array|MockResponse $failure, ?VideoProviderProblem $expected): void
    {
        $records = ['/3/find/777' => $failure];
        $request = new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tvdb' => '777', 'imdb' => 'tt123']);

        self::assertSame($expected, $this->provider($this->http($records))->identify($request));
        self::assertNotContains('/3/find/tt123', $this->requests);
    }

    public function testAFailedSeriesDetailLookupDoesNotFallThroughToImdb(): void
    {
        $records = [
            '/3/find/777' => ['tv_results' => [['id' => 10]]],
            '/3/tv/10' => new MockResponse('{}', ['http_code' => 503]),
        ];
        $request = new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tvdb' => '777', 'imdb' => 'tt123']);

        self::assertSame(VideoProviderProblem::Unavailable, $this->provider($this->http($records))->identify($request));
        self::assertNotContains('/3/find/tt123', $this->requests);
    }

    /** @return iterable<string, array{array<string, int|string>}> */
    public static function conflictingSuppliedIds(): iterable
    {
        yield 'another TVDB identity' => [['tvdb_id' => 888, 'imdb_id' => 'tt123']];
        yield 'another IMDb identity' => [['tvdb_id' => 777, 'imdb_id' => 'tt456']];
    }

    /** @param array<string, int|string> $externalIds */
    #[DataProvider('conflictingSuppliedIds')]
    public function testASeriesFoundByTvdbCannotContradictItsSuppliedIdentities(array $externalIds): void
    {
        $records = $this->seriesRecords();
        $records['/3/find/777'] = ['tv_results' => [['id' => 10]]];
        $records['/3/tv/10']['external_ids'] = $externalIds;
        $request = new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tvdb' => '777', 'imdb' => 'tt123']);

        self::assertNull($this->provider($this->http($records))->identify($request));
        self::assertNotContains('/3/find/tt123', $this->requests);
        self::assertNotContains('/3/tv/10/season/5', $this->requests);
    }

    public function testAnImdbFallbackCannotSelectASeriesWithAnotherTvdbIdentity(): void
    {
        $records = $this->seriesRecords();
        $records['/3/find/777'] = ['tv_results' => []];
        $records['/3/find/tt123'] = ['tv_results' => [['id' => 10]]];
        $records['/3/tv/10']['external_ids'] = ['tvdb_id' => 888, 'imdb_id' => 'tt123'];
        $request = new VideoRequest(VideoKind::Series, 'Show', externalIds: ['tvdb' => '777', 'imdb' => 'tt123']);

        self::assertNull($this->provider($this->http($records))->identify($request));
        self::assertContains('/3/find/tt123', $this->requests);
        self::assertNotContains('/3/tv/10/season/5', $this->requests);
    }

    public function testMovieIdentificationStillUsesItsImdbIdWhenATvdbIdIsAlsoSupplied(): void
    {
        $records = [
            '/3/find/tt123' => ['movie_results' => [['id' => 12]]],
            '/3/movie/12' => ['id' => 12, 'title' => 'Film'],
        ];
        $request = new VideoRequest(VideoKind::Movie, 'Film', externalIds: ['tvdb' => '777', 'imdb' => 'tt123']);

        $title = $this->provider($this->http($records))->identify($request);

        self::assertInstanceOf(VideoTitle::class, $title);
        self::assertSame('12', $title->entry->id);
        self::assertSame(['/3/find/tt123', '/3/movie/12'], $this->requests);
    }

    private function provider(MockHttpClient $http, bool $enabled = true, string $token = 'fixture-token'): TmdbVideoMetadata
    {
        $client = new PublicHttpClient($http, new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1']));
        $switches = $enabled ? ProviderSwitches::allEnabled() : ProviderSwitches::withDisabled(MetadataProvider::Tmdb);

        return new TmdbVideoMetadata(new TmdbAdapter($token, new NullLogger(), new JsonEncoder(), $client, $switches));
    }

    /** @param array<string, array<string, mixed>|MockResponse> $records */
    private function http(array $records): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($records): MockResponse {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->requests[] = $path;
            self::assertArrayHasKey($path, $records);
            $record = $records[$path];
            if ($path === '/3/find/777') {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                self::assertSame('tvdb_id', $query['external_source']);
            }
            if ($path === '/3/find/tt123') {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                self::assertSame('imdb_id', $query['external_source']);
            }

            return $record instanceof MockResponse ? $record : new MockResponse(json_encode($record, JSON_THROW_ON_ERROR));
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function seriesRecords(): array
    {
        $first = ['id' => 100, 'name' => 'First', 'episode_number' => 1, 'season_number' => 5, 'air_date' => '2011-04-17'];
        $later = ['id' => 102, 'name' => 'Later', 'episode_number' => 12, 'season_number' => 5];

        return [
            '/3/tv/10' => [
                'id' => 10, 'name' => 'Show', 'first_air_date' => '2011-04-17', 'seasons' => [['season_number' => 5]],
                'episode_groups' => ['results' => [
                    ['id' => 'dvd-group', 'name' => 'DVD', 'type' => 3],
                    ['id' => 'absolute-group', 'name' => 'Absolute', 'type' => 2],
                    ['id' => 'digital-group', 'name' => 'Digital', 'type' => 4],
                ]],
            ],
            '/3/tv/10/season/5' => ['season_number' => 5, 'episodes' => [$later, $first]],
            '/3/tv/episode_group/absolute-group' => ['groups' => [
                ['order' => 1, 'name' => 'Second group', 'episodes' => [array_merge($later, ['order' => 0])]],
                ['order' => 0, 'name' => 'First group', 'episodes' => [array_merge($first, ['order' => 0])]],
            ]],
            '/3/tv/episode_group/dvd-group' => ['groups' => [
                ['order' => 0, 'name' => 'DVD season', 'episodes' => [array_merge($first, ['order' => 1]), array_merge($later, ['order' => 0])]],
            ]],
        ];
    }
}
