<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api\MusicBrainz;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCoverImage;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCreditedArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRejected;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzSlotWait;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzUnavailable;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Yaml\Yaml;

final class MusicBrainzAdapterTest extends TestCase
{
    private const string RELEASE_ID = 'f3c3a3b8-5d1e-4c55-9a0e-7a51f4c2d001';
    private const string RECORDING_ID = '0a6f5c4e-2b1d-4e3f-8a7b-9c0d1e2f3a01';
    private const string POST_MALONE = 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19';
    private const string BLAKE_SHELTON = '2b1d4a4e-7c0f-4d6e-9a1b-3c5d7e9f1a02';

    /** @var list<string> */
    private array $urls = [];
    /** @var list<MockResponse> */
    private array $responses = [];
    /** @var \ArrayObject<int, array{?string, ?float}> the key and longest wait of every turn taken */
    private \ArrayObject $turns;

    protected function setUp(): void
    {
        $this->turns = new \ArrayObject();
    }

    public function testReleaseSearchSendsOneEscapedQuotedQueryAndReturnsSummaries(): void
    {
        $this->respond(self::json(self::releaseSearchBody()));

        $releases = $this->adapter()->findReleases('F-1 Trillion: Long Bed', 'Post Malone');

        self::assertCount(1, $this->urls);
        self::assertSame('/ws/2/release', parse_url($this->urls[0], PHP_URL_PATH));
        self::assertSame([
            'query' => 'release:"F\-1 Trillion\: Long Bed" AND artist:"Post Malone"',
            'limit' => '25',
            'fmt' => 'json',
        ], $this->query(0));
        self::assertIsList($releases);
        self::assertCount(2, $releases);

        $explicit = $releases[0];
        self::assertSame(self::RELEASE_ID, $explicit->id);
        self::assertSame('F-1 Trillion: Long Bed', $explicit->title);
        self::assertSame(100, $explicit->score);
        self::assertSame('2024-08-18', $explicit->date);
        self::assertSame('XW', $explicit->country);
        self::assertSame('Official', $explicit->status);
        self::assertSame('explicit', $explicit->disambiguation);
        self::assertSame('602475941234', $explicit->barcode);
        self::assertSame('Mercury Records', $explicit->labels[0]->name);
        self::assertSame('00602475941234', $explicit->labels[0]->catalogNumber);
        self::assertSame(['Digital Media'], $explicit->formats());
        self::assertSame(36, $explicit->trackCount);
        self::assertSame(1, $explicit->mediumCount());
        self::assertSame('Post Malone', $explicit->artistCredit->text());
        self::assertSame(self::POST_MALONE, $explicit->artistCredit->artists[0]->artistId);
        self::assertSame('b8a6c1a0-0d6e-4c1f-9b3a-1e2d3c4b5a01', $explicit->releaseGroup?->id);
        self::assertSame('Album', $explicit->releaseGroup->primaryType);

        $clean = $releases[1];
        self::assertSame(98, $clean->score);
        self::assertSame(['CD'], $clean->formats());
        self::assertSame(2, $clean->mediumCount());
        self::assertSame(37, $clean->trackCount, 'Without a total, the media track counts add up.');
        self::assertSame([], $clean->labels);
        self::assertNull($clean->barcode);
    }

    public function testReleaseSearchWithoutAnArtistSearchesTheTitleAlone(): void
    {
        $this->respond(self::json(['count' => 0, 'releases' => []]));

        self::assertSame([], $this->adapter()->findReleases('Now 100', null));
        self::assertSame('release:"Now 100"', $this->query(0)['query']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function luceneTitles(): iterable
    {
        yield 'colon and exclamation mark' => ['Help!: The Remix', 'Help\!\: The Remix'];
        yield 'slash' => ['AC/DC', 'AC\/DC'];
        yield 'quotes and brackets' => ['Say "Hi" (Live) [Remix] {x}', 'Say \"Hi\" \(Live\) \[Remix\] \{x\}'];
        yield 'backslash' => ['Back\Slash', 'Back\\\\Slash'];
        yield 'operators' => ['Rock && Roll || +More -Less', 'Rock \&\& Roll \|\| \+More \-Less'];
        yield 'wildcards and boosts' => ['What? Yes~ ^Up*', 'What\? Yes\~ \^Up\*'];
    }

    #[DataProvider('luceneTitles')]
    public function testLuceneSpecialsAreEscapedInsideTheQuotedPhrase(string $title, string $escaped): void
    {
        $this->respond(self::json(['count' => 0, 'releases' => []]), self::json(['count' => 0, 'recordings' => []]));
        $adapter = $this->adapter();

        $adapter->findReleases($title, $title);
        $adapter->findRecordings($title, null);

        self::assertSame(sprintf('release:"%1$s" AND artist:"%1$s"', $escaped), $this->query(0)['query']);
        self::assertSame(sprintf('recording:"%s"', $escaped), $this->query(1)['query']);
    }

    public function testReleaseLookupReturnsOrderedMediaTracksCreditsAndGenres(): void
    {
        $this->respond(self::json(self::releaseBody()));

        $release = $this->adapter()->fetchRelease(self::RELEASE_ID);

        self::assertSame('/ws/2/release/' . self::RELEASE_ID, parse_url($this->urls[0], PHP_URL_PATH));
        self::assertSame([
            'inc' => 'recordings+artist-credits+labels+release-groups+genres',
            'fmt' => 'json',
        ], $this->query(0));
        self::assertInstanceOf(MusicBrainzRelease::class, $release);
        self::assertSame('F-1 Trillion: Long Bed', $release->title);
        self::assertSame('2024-08-18', $release->date);
        self::assertSame('XW', $release->country);
        self::assertSame('602475941234', $release->barcode);
        self::assertSame('Mercury Records', $release->labels[0]->name);
        self::assertSame('c6a1f0e2-3b4d-4e5f-8a9b-0c1d2e3f4a01', $release->labels[0]->labelId);
        self::assertSame(['Digital Media'], $release->formats());
        self::assertTrue($release->hasFrontCover);
        self::assertSame(3, $release->trackCount());

        self::assertSame([1, 2], array_column($release->media, 'position'), 'Media come in disc order.');
        [$first, $second] = $release->media;
        self::assertSame([1, 2], array_column($first->tracks, 'position'), 'Tracks come in track order.');
        $opener = $first->tracks[0];
        self::assertSame('d1e2f3a4-b5c6-4d7e-8f9a-0b1c2d3e4f01', $opener->id);
        self::assertSame('1', $opener->number);
        self::assertSame('Wild Horses', $opener->title);
        self::assertSame(210_000, $opener->lengthMs, 'The track length on this release wins over the recording length.');
        self::assertSame(self::RECORDING_ID, $opener->recordingId);
        self::assertSame('Post Malone feat. Blake Shelton', $opener->artistCredit->text());
        self::assertEquals([
            new MusicBrainzCreditedArtist(self::POST_MALONE, 'Post Malone', ' feat. ', 'Post Malone', 'Malone, Post'),
            new MusicBrainzCreditedArtist(self::BLAKE_SHELTON, 'Blake Shelton', '', 'Blake Shelton', 'Shelton, Blake'),
        ], $opener->artistCredit->artists);
        $unknownLength = $first->tracks[1];
        self::assertSame(180_000, $unknownLength->lengthMs, 'Without a track length, the recording length counts.');
        self::assertSame('Post Malone', $unknownLength->artistCredit->text(), 'Without a track credit, the recording credit counts.');
        self::assertSame('A1', $second->tracks[0]->number);

        self::assertEquals([
            new MusicBrainzCreditedArtist(self::POST_MALONE, 'Posty', '', 'Post Malone', 'Malone, Post'),
        ], $release->artistCredit->artists, 'The credited name stays apart from the artist name.');
        self::assertSame('b8a6c1a0-0d6e-4c1f-9b3a-1e2d3c4b5a01', $release->releaseGroup?->id);
        self::assertSame('2024-08-16', $release->releaseGroup->firstReleaseDate);
        self::assertSame(['country', 'pop', 'country pop'], $release->releaseGroup->genres, 'Genres come most votes first.');
        self::assertSame(['country'], $release->genres);
    }

    public function testRecordingSearchReturnsLengthCreditAndReleases(): void
    {
        $this->respond(self::json(self::recordingSearchBody()));

        $recordings = $this->adapter()->findRecordings('Pour Me a Drink', 'Post Malone');

        self::assertSame('/ws/2/recording', parse_url($this->urls[0], PHP_URL_PATH));
        self::assertSame('recording:"Pour Me a Drink" AND artist:"Post Malone"', $this->query(0)['query']);
        self::assertSame('25', $this->query(0)['limit']);
        self::assertIsList($recordings);
        self::assertCount(1, $recordings);
        $recording = $recordings[0];
        self::assertSame(self::RECORDING_ID, $recording->id);
        self::assertSame(97, $recording->score);
        self::assertSame(203_000, $recording->lengthMs);
        self::assertSame('Post Malone feat. Blake Shelton', $recording->artistCredit->text());
        self::assertSame([self::RELEASE_ID, 'e4f5a6b7-c8d9-4e0f-9a1b-2c3d4e5f6a01'], array_column($recording->releases, 'id'));
        self::assertSame(36, $recording->releases[0]->trackCount);
        self::assertSame('Album', $recording->releases[0]->releaseGroup?->primaryType);
        self::assertNull($recording->releases[1]->releaseGroup);
    }

    public function testRecordingLookupAsksForCreditsAndReleases(): void
    {
        $body = self::recordingSearchBody()['recordings'][0];
        unset($body['score']);
        $this->respond(self::json($body));

        $recording = $this->adapter()->fetchRecording(self::RECORDING_ID);

        self::assertSame('/ws/2/recording/' . self::RECORDING_ID, parse_url($this->urls[0], PHP_URL_PATH));
        self::assertSame('artist-credits+releases+release-groups', $this->query(0)['inc']);
        self::assertInstanceOf(MusicBrainzRecording::class, $recording);
        self::assertSame(0, $recording->score);
        self::assertSame('Pour Me a Drink', $recording->title);
        self::assertCount(2, $recording->releases);
    }

    public function testArtistSearchAndLookupReturnTheArtistDetails(): void
    {
        $artist = [
            'id' => self::POST_MALONE, 'name' => 'Post Malone', 'sort-name' => 'Malone, Post', 'type' => 'Person',
            'country' => 'US', 'disambiguation' => 'US rapper', 'life-span' => ['begin' => '1995-07-04', 'end' => null, 'ended' => false],
        ];
        $this->respond(self::json(['count' => 1, 'artists' => [[...$artist, 'score' => 100]]]), self::json($artist));
        $adapter = $this->adapter();

        $found = $adapter->findArtists('Post Malone');
        $fetched = $adapter->fetchArtist(self::POST_MALONE);

        self::assertSame('artist:"Post Malone"', $this->query(0)['query']);
        self::assertSame('/ws/2/artist/' . self::POST_MALONE, parse_url($this->urls[1], PHP_URL_PATH));
        self::assertEquals(
            [new MusicBrainzArtist(self::POST_MALONE, 'Post Malone', 100, 'Malone, Post', 'Person', 'US', 'US rapper', '1995-07-04', null, false)],
            $found,
        );
        self::assertEquals(
            new MusicBrainzArtist(self::POST_MALONE, 'Post Malone', 0, 'Malone, Post', 'Person', 'US', 'US rapper', '1995-07-04', null, false),
            $fetched,
        );
    }

    /** @return iterable<string, array{string, int, class-string|null}> */
    public static function errorStatuses(): iterable
    {
        foreach (['findReleases', 'findRecordings', 'findArtists'] as $search) {
            yield $search . ' 503' => [$search, 503, MusicBrainzUnavailable::class];
            yield $search . ' 429' => [$search, 429, MusicBrainzUnavailable::class];
            yield $search . ' 404' => [$search, 404, MusicBrainzRejected::class];
            yield $search . ' 400' => [$search, 400, MusicBrainzRejected::class];
        }
        foreach (['fetchRelease', 'fetchRecording', 'fetchArtist'] as $lookup) {
            yield $lookup . ' 503' => [$lookup, 503, MusicBrainzUnavailable::class];
            yield $lookup . ' 429' => [$lookup, 429, MusicBrainzUnavailable::class];
            yield $lookup . ' 404' => [$lookup, 404, null];
            yield $lookup . ' 400' => [$lookup, 400, MusicBrainzRejected::class];
        }
    }

    /** @param class-string|null $expected */
    #[DataProvider('errorStatuses')]
    public function testErrorStatusesAreToldApartAndNeverReadAsAnEmptyList(string $method, int $status, ?string $expected): void
    {
        // A 503 from MusicBrainz's rate limit carries an HTML body, not JSON.
        $this->respond(new MockResponse('<html>busy</html>', ['http_code' => $status]));

        $result = self::call($this->adapter(), $method);

        if ($expected === null) {
            self::assertNull($result);

            return;
        }
        self::assertInstanceOf($expected, $result);
        if ($result instanceof MusicBrainzRejected) {
            self::assertSame($status, $result->status);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function portMethods(): iterable
    {
        foreach (['findReleases', 'fetchRelease', 'findRecordings', 'fetchRecording', 'findArtists', 'fetchArtist', 'fetchFrontCover'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('portMethods')]
    public function testTransportFailuresAndUnreadableBodiesAreUnavailable(string $method): void
    {
        $this->respond(new MockResponse('', ['error' => 'host unreachable']));
        self::assertInstanceOf(MusicBrainzUnavailable::class, self::call($this->adapter(), $method));

        if ($method !== 'fetchFrontCover') {
            $this->respond(new MockResponse('not json'));
            self::assertInstanceOf(MusicBrainzUnavailable::class, self::call($this->adapter(), $method));
        }
    }

    #[DataProvider('portMethods')]
    public function testEveryCallTakesATurnUnderOneSharedKeyBeforeItsRequest(string $method): void
    {
        $this->respond(self::json(['id' => self::RELEASE_ID, 'count' => 0]), self::json(['id' => self::RELEASE_ID, 'count' => 0]));
        $adapter = $this->adapter($this->spyLimiter());

        self::call($adapter, $method, MusicBrainzSlotWait::Bounded);
        self::call($adapter, $method, MusicBrainzSlotWait::Unbounded);

        self::assertCount(2, $this->urls);
        self::assertSame([MusicBrainzSlotWait::BOUNDED_SECONDS, null], array_column($this->turns->getArrayCopy(), 1));
        self::assertCount(1, array_column($this->turns->getArrayCopy(), 0) |> array_unique(...), 'Every call spends the same budget.');
    }

    #[DataProvider('portMethods')]
    public function testABoundedCallWhoseTurnIsMoreThanTenSecondsAwayAnswersUnavailableWithoutWaiting(string $method): void
    {
        $shared = self::shippedLimiter('musicbrainz');
        $limiter = self::fixedKey($shared);
        // Eleven turns are taken: the next one comes eleven seconds from now.
        for ($turn = 0; $turn < 11; ++$turn) {
            $limiter->create()->reserve();
        }
        $started = hrtime(true);

        $result = self::call($this->adapter($limiter), $method, MusicBrainzSlotWait::Bounded);

        self::assertInstanceOf(MusicBrainzUnavailable::class, $result);
        self::assertSame([], $this->urls, 'No request goes out without a turn.');
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
    }

    public function testOlderSearchesFoldTheArtistIntoTheEscapedQuery(): void
    {
        $this->respond(
            self::json(self::recordingSearchBody()),
            self::json(['count' => 0, 'release-groups' => []]),
            self::json(['count' => 0, 'artists' => []]),
        );
        $adapter = $this->adapter();

        $result = $adapter->searchRecording('Re: Pour Me a Drink', 'Post Malone');
        $adapter->searchReleaseGroup('F-1 Trillion', null);
        $adapter->searchArtist('AC/DC');

        self::assertSame(['query' => '(Re\: Pour Me a Drink) AND artist:"Post Malone"', 'limit' => '25', 'fmt' => 'json'], $this->query(0));
        self::assertSame('F\-1 Trillion', $this->query(1)['query']);
        self::assertSame('AC\/DC', $this->query(2)['query']);
        self::assertSame('Pour Me a Drink', $result->recordings[0]->title);
        self::assertSame(1, $result->total);
    }

    public function testOlderMethodsAnswerServiceUnavailableWhenABoundedTurnIsTooFarAway(): void
    {
        $limiter = self::fixedKey(self::shippedLimiter('musicbrainz'));
        for ($turn = 0; $turn < 11; ++$turn) {
            $limiter->create()->reserve();
        }

        try {
            $this->adapter($limiter)->searchArtist('Post Malone', wait: MusicBrainzSlotWait::Bounded);
            self::fail('A bounded call without a turn must not read as an empty result.');
        } catch (ServiceUnavailableException) {
            self::assertSame([], $this->urls);
        }
    }

    public function testOlderMethodsWaitWithoutLimitByDefault(): void
    {
        $this->respond(self::json(['count' => 0, 'artists' => []]), self::json(['id' => self::POST_MALONE, 'name' => 'Post Malone']));
        $adapter = $this->adapter($this->spyLimiter());

        $adapter->searchArtist('Post Malone');
        $adapter->lookupArtist(self::POST_MALONE);

        self::assertSame([null, null], array_column($this->turns->getArrayCopy(), 1));
    }

    public function testFrontCoverComesFromTheCoverArtArchiveAfterATurn(): void
    {
        $this->respond(new MockResponse('jpeg-bytes', ['response_headers' => ['Content-Type' => 'image/jpeg']]));

        $cover = $this->adapter($this->spyLimiter())->fetchFrontCover(self::RELEASE_ID);

        self::assertEquals(new MusicBrainzCoverImage('jpeg-bytes', 'image/jpeg'), $cover);
        self::assertSame('https://coverartarchive.org/release/' . self::RELEASE_ID . '/front-1200', $this->urls[0]);
        self::assertCount(1, $this->turns);
    }

    private function adapter(?RateLimiterFactoryInterface $limiter = null): MusicBrainzAdapter
    {
        $raw = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->urls[] = $url;

            return array_shift($this->responses) ?? throw new \LogicException('No response is queued for ' . $url);
        });
        $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        $logger = new NullLogger();

        return new MusicBrainzAdapter(
            $logger,
            new JsonEncoder(),
            $client,
            $limiter ?? new RateLimiterFactory(['id' => 'musicbrainz', 'policy' => 'no_limit'], new InMemoryStorage()),
            new CoverArtArchiveAdapter($logger, new JsonEncoder(), $client),
        );
    }

    private function respond(MockResponse ...$responses): void
    {
        array_push($this->responses, ...$responses);
    }

    /** @return array<array-key, mixed> the decoded query string of a request */
    private function query(int $request): array
    {
        parse_str((string) parse_url($this->urls[$request], PHP_URL_QUERY), $query);

        return $query;
    }

    private static function call(MusicBrainzLookupInterface $lookup, string $method, MusicBrainzSlotWait $wait = MusicBrainzSlotWait::Bounded): mixed
    {
        return match ($method) {
            'findReleases' => $lookup->findReleases('F-1 Trillion: Long Bed', 'Post Malone', $wait),
            'fetchRelease' => $lookup->fetchRelease(self::RELEASE_ID, $wait),
            'findRecordings' => $lookup->findRecordings('Pour Me a Drink', 'Post Malone', $wait),
            'fetchRecording' => $lookup->fetchRecording(self::RECORDING_ID, $wait),
            'findArtists' => $lookup->findArtists('Post Malone', $wait),
            'fetchArtist' => $lookup->fetchArtist(self::POST_MALONE, $wait),
            'fetchFrontCover' => $lookup->fetchFrontCover(self::RELEASE_ID, $wait),
            default => throw new \LogicException('Unknown port method ' . $method),
        };
    }

    /** @param array<array-key, mixed> $body */
    private static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /** A limiter with the configuration the application ships, on in-memory storage. */
    private static function shippedLimiter(string $name): RateLimiterFactory
    {
        $config = Yaml::parseFile(dirname(__DIR__, 6) . '/config/packages/framework.yaml')['framework']['rate_limiter'][$name];

        return new RateLimiterFactory(['id' => $name, ...$config], new InMemoryStorage());
    }

    /** Hands out one limiter whatever key is asked for, so a test can spend the budget first. */
    private static function fixedKey(RateLimiterFactoryInterface $factory): RateLimiterFactoryInterface
    {
        return new class($factory) implements RateLimiterFactoryInterface {
            public function __construct(private readonly RateLimiterFactoryInterface $factory)
            {
            }

            public function create(?string $key = null): LimiterInterface
            {
                return $this->factory->create('shared');
            }
        };
    }

    /** A limiter that grants every turn at once and records each key and longest wait. */
    private function spyLimiter(): RateLimiterFactoryInterface
    {
        return new class($this->turns) implements RateLimiterFactoryInterface {
            /** @param \ArrayObject<int, array{?string, ?float}> $turns */
            public function __construct(private readonly \ArrayObject $turns)
            {
            }

            public function create(?string $key = null): LimiterInterface
            {
                return new class($this->turns, $key) implements LimiterInterface {
                    /** @param \ArrayObject<int, array{?string, ?float}> $turns */
                    public function __construct(private readonly \ArrayObject $turns, private readonly ?string $key)
                    {
                    }

                    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
                    {
                        $this->turns->append([$this->key, $maxTime]);

                        return new Reservation(microtime(true), new RateLimit(1, new \DateTimeImmutable(), true, 1));
                    }

                    public function consume(int $tokens = 1): RateLimit
                    {
                        throw new \LogicException('MusicBrainz calls reserve a turn.');
                    }

                    public function reset(): void
                    {
                    }
                };
            }
        };
    }

    /** @return array<string, mixed> */
    private static function credit(string $id, string $name, string $sortName, ?string $joinPhrase = null, ?string $credited = null): array
    {
        $entry = ['name' => $credited ?? $name, 'artist' => ['id' => $id, 'name' => $name, 'sort-name' => $sortName]];

        // Search results leave out an empty join phrase; lookups send it.
        return $joinPhrase === null ? $entry : [...$entry, 'joinphrase' => $joinPhrase];
    }

    /** @return array<string, mixed> */
    private static function releaseSearchBody(): array
    {
        return ['created' => '2026-11-14T12:00:00.000Z', 'count' => 2, 'offset' => 0, 'releases' => [
            [
                'id' => self::RELEASE_ID, 'score' => 100, 'status' => 'Official', 'title' => 'F-1 Trillion: Long Bed',
                'disambiguation' => 'explicit', 'date' => '2024-08-18', 'country' => 'XW', 'barcode' => '602475941234',
                'artist-credit' => [self::credit(self::POST_MALONE, 'Post Malone', 'Malone, Post')],
                'release-group' => ['id' => 'b8a6c1a0-0d6e-4c1f-9b3a-1e2d3c4b5a01', 'primary-type' => 'Album', 'title' => 'F-1 Trillion: Long Bed'],
                'label-info' => [['catalog-number' => '00602475941234', 'label' => ['id' => 'c6a1f0e2-3b4d-4e5f-8a9b-0c1d2e3f4a01', 'name' => 'Mercury Records']]],
                'track-count' => 36,
                'media' => [['format' => 'Digital Media', 'disc-count' => 0, 'track-count' => 36]],
            ],
            [
                'id' => 'e4f5a6b7-c8d9-4e0f-9a1b-2c3d4e5f6a01', 'score' => 98, 'status' => 'Official', 'title' => 'F-1 Trillion: Long Bed',
                'disambiguation' => 'clean', 'date' => '2024-08-18', 'country' => 'US', 'barcode' => '',
                'artist-credit' => [self::credit(self::POST_MALONE, 'Post Malone', 'Malone, Post')],
                'media' => [['format' => 'CD', 'track-count' => 18], ['format' => 'CD', 'track-count' => 19]],
            ],
        ]];
    }

    /** @return array<string, mixed> */
    private static function releaseBody(): array
    {
        $postMalone = self::credit(self::POST_MALONE, 'Post Malone', 'Malone, Post', '');
        $recording = static fn(string $id, ?int $length): array => [
            'id' => $id, 'title' => 'Recording', 'length' => $length, 'artist-credit' => [$postMalone],
        ];

        return [
            'id' => self::RELEASE_ID, 'title' => 'F-1 Trillion: Long Bed', 'status' => 'Official', 'date' => '2024-08-18',
            'country' => 'XW', 'barcode' => '602475941234', 'disambiguation' => 'explicit',
            'artist-credit' => [self::credit(self::POST_MALONE, 'Post Malone', 'Malone, Post', '', 'Posty')],
            'label-info' => [['catalog-number' => '00602475941234', 'label' => ['id' => 'c6a1f0e2-3b4d-4e5f-8a9b-0c1d2e3f4a01', 'name' => 'Mercury Records']]],
            'release-group' => [
                'id' => 'b8a6c1a0-0d6e-4c1f-9b3a-1e2d3c4b5a01', 'title' => 'F-1 Trillion: Long Bed', 'primary-type' => 'Album',
                'first-release-date' => '2024-08-16',
                'genres' => [
                    ['id' => 'g1', 'name' => 'country', 'count' => 5, 'disambiguation' => ''],
                    ['id' => 'g2', 'name' => 'country pop', 'count' => 1, 'disambiguation' => ''],
                    ['id' => 'g3', 'name' => 'pop', 'count' => 2, 'disambiguation' => ''],
                ],
            ],
            'genres' => [['id' => 'g1', 'name' => 'country', 'count' => 1, 'disambiguation' => '']],
            'cover-art-archive' => ['artwork' => true, 'count' => 1, 'front' => true, 'back' => false, 'darkened' => false],
            'media' => [
                [
                    'position' => 2, 'format' => 'Digital Media', 'title' => '', 'track-count' => 1,
                    'tracks' => [[
                        'id' => 'd1e2f3a4-b5c6-4d7e-8f9a-0b1c2d3e4f03', 'position' => 1, 'number' => 'A1', 'title' => 'Bonus',
                        'length' => 150_000, 'artist-credit' => [$postMalone],
                        'recording' => $recording('0a6f5c4e-2b1d-4e3f-8a7b-9c0d1e2f3a03', 150_000),
                    ]],
                ],
                [
                    'position' => 1, 'format' => 'Digital Media', 'title' => '', 'track-count' => 2,
                    'tracks' => [
                        [
                            'id' => 'd1e2f3a4-b5c6-4d7e-8f9a-0b1c2d3e4f02', 'position' => 2, 'number' => '2', 'title' => 'Unknown Length',
                            'length' => null,
                            'recording' => $recording('0a6f5c4e-2b1d-4e3f-8a7b-9c0d1e2f3a02', 180_000),
                        ],
                        [
                            'id' => 'd1e2f3a4-b5c6-4d7e-8f9a-0b1c2d3e4f01', 'position' => 1, 'number' => '1', 'title' => 'Wild Horses',
                            'length' => 210_000,
                            'artist-credit' => [
                                self::credit(self::POST_MALONE, 'Post Malone', 'Malone, Post', ' feat. '),
                                self::credit(self::BLAKE_SHELTON, 'Blake Shelton', 'Shelton, Blake', ''),
                            ],
                            'recording' => $recording(self::RECORDING_ID, 209_000),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function recordingSearchBody(): array
    {
        return ['count' => 1, 'recordings' => [[
            'id' => self::RECORDING_ID, 'score' => 97, 'title' => 'Pour Me a Drink', 'length' => 203_000,
            'artist-credit' => [
                self::credit(self::POST_MALONE, 'Post Malone', 'Malone, Post', ' feat. '),
                self::credit(self::BLAKE_SHELTON, 'Blake Shelton', 'Shelton, Blake'),
            ],
            'releases' => [
                [
                    'id' => self::RELEASE_ID, 'title' => 'F-1 Trillion', 'status' => 'Official', 'date' => '2024-08-16',
                    'country' => 'XW', 'track-count' => 36,
                    'release-group' => ['id' => 'b8a6c1a0-0d6e-4c1f-9b3a-1e2d3c4b5a01', 'title' => 'F-1 Trillion', 'primary-type' => 'Album'],
                ],
                ['id' => 'e4f5a6b7-c8d9-4e0f-9a1b-2c3d4e5f6a01', 'title' => 'Pour Me a Drink'],
            ],
        ]]];
    }
}
