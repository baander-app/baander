<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Infrastructure\Api;

use App\Lyrics\Application\DTO\LrclibRejected;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\DTO\LrclibWait;
use App\Lyrics\Infrastructure\Api\LrclibClient;
use App\Lyrics\Infrastructure\Api\LrclibPauseInterface;
use App\Shared\Application\Exception\RateLimiterUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * No test here waits: the client's clock is a stand-in that a sleep moves forward, the pause
 * every process honours is kept in this test, and turns under the shared pace come at once
 * unless a test queues a wait for one.
 */
final class LrclibClientTest extends TestCase
{
    private const string STARTED_AT = '2026-11-15 12:00:00';
    private const string USER_AGENT = 'Baander/1.0 (https://baander.app; contact@baander.app)';

    private HttpClientInterface&Stub $httpClient;
    private LoggerInterface $logger;
    private LrclibClient $client;
    private MockClock $clock;
    /** Microseconds since the epoch at which the test started. */
    private int $startedAt;
    /** Microseconds since the epoch at which the pause every process honours ends. */
    private int $pausedUntil = 0;
    private bool $pauseReadsFail = false;
    private bool $pauseWritesFail = false;
    /** @var list<int> the seconds of every pause the client stored, in order */
    private array $pauses = [];
    /** @var list<array{key: ?string, longestWait: ?float}> every turn reserved under the shared pace */
    private array $turns = [];
    /** @var list<\Closure(): float> what happens while each next turn is reserved, answering the seconds until that turn */
    private array $turnWaits = [];
    /** @var list<array{url: string, at: float, headers: list<string>}> every request sent, with the seconds since the test started */
    private array $requests = [];
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::STARTED_AT);
        $this->startedAt = $this->nowMicroseconds();
        $this->httpClient = $this->createStub(HttpClientInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->client = $this->createClient();
    }

    private function createClient(?HttpClientInterface $httpClient = null): LrclibClient
    {
        return new LrclibClient(
            $httpClient ?? $this->httpClient,
            $this->logger,
            $this->limiter(),
            $this->pause(),
            $this->clock,
            'https://lrclib.net',
            $this->clock->sleep(...),
        );
    }

    // --- getBySignature ---

    public function testGetBySignatureMapsEveryFieldOfTheAnswer(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'id' => 123,
            'trackName' => 'Test Song',
            'artistName' => 'Test Artist',
            'albumName' => 'Test Album',
            'duration' => 200,
            'instrumental' => false,
            'plainLyrics' => 'Some lyrics',
            'syncedLyrics' => '[00:01.00] Some lyrics',
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->client->getBySignature('Test Song', 'Test Artist', 'Test Album', 200.0);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertSame(123, $result->id);
        $this->assertSame('Test Song', $result->trackName);
        $this->assertSame('Test Artist', $result->artistName);
        $this->assertSame('Test Album', $result->albumName);
        $this->assertSame(200.0, $result->duration);
        $this->assertFalse($result->instrumental);
        $this->assertSame('Some lyrics', $result->plainLyrics);
        $this->assertSame('[00:01.00] Some lyrics', $result->syncedLyrics);
    }

    public function testASignatureLookupSendsOneRequestToTheGetEndpoint(): void
    {
        $client = $this->clientAnswering(self::lyrics());

        $result = $client->getBySignature('Still Alive', 'GLaDOS', 'Portal', 175.0);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertSame('This was a triumph', $result->plainLyrics);
        $this->assertCount(1, $this->requests);
        $this->assertSame('/api/get', parse_url($this->requests[0]['url'], PHP_URL_PATH));
        $this->assertSame('Still Alive', $this->queryParameter($this->requests[0]['url'], 'track_name'));
        $this->assertSame('GLaDOS', $this->queryParameter($this->requests[0]['url'], 'artist_name'));
        $this->assertSame('Portal', $this->queryParameter($this->requests[0]['url'], 'album_name'));
    }

    public function testGetBySignatureReturnsNullOn404(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->client = $this->createClient();

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $result = $this->client->getBySignature('Unknown', 'Artist', 'Album', 100.0);

        $this->assertNull($result);
    }

    public function testGetBySignatureReportsAnHttpErrorAsUnavailable(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createClient();

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);

        $this->httpClient->expects($this->once())->method('request')->willReturn($response);
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignature('Song', 'Artist', 'Album', 100.0);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
    }

    // --- getById ---

    public function testGetByIdReturnsResult(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'id' => 555,
            'trackName' => 'ById Song',
            'artistName' => 'Artist',
            'albumName' => 'Album',
            'duration' => 300,
            'instrumental' => false,
            'plainLyrics' => 'lyrics',
            'syncedLyrics' => null,
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->client->getById(555);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertSame(555, $result->id);
        $this->assertSame('ById Song', $result->trackName);
    }

    public function testGetByIdReturnsNullOn404(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->client->getById(99999);

        $this->assertNull($result);
    }

    public function testGetByIdReportsAServerErrorAsUnavailable(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);

        $this->httpClient->method('request')->willReturn($response);

        $this->assertInstanceOf(LrclibUnavailable::class, $this->client->getById(555));
    }

    public function testARequestTimeoutAnswerIsReportedAsUnavailable(): void
    {
        $this->httpClient->method('request')->willReturn($this->answer(408));

        $this->assertInstanceOf(LrclibUnavailable::class, $this->client->getBySignature('Song', 'Artist', 'Album', 100.0));
    }

    /** A client error other than 404, 408 or 429 is LRCLIB refusing the request, which a retry does not change. */
    #[TestWith([400])]
    #[TestWith([401])]
    #[TestWith([403])]
    #[TestWith([422])]
    public function testAClientErrorIsReportedAsARejectedRequest(int $status): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createClient();
        $this->httpClient->method('request')->willReturn($this->answer($status));
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $bySignature = $this->client->getBySignature('Amore Infelice', 'Suspekt', 'Elektra', 0.238);

        $this->assertInstanceOf(LrclibRejected::class, $bySignature);
        $this->assertSame($status, $bySignature->status);
        $this->assertInstanceOf(LrclibRejected::class, $this->client->getById(555));
        $this->assertInstanceOf(LrclibRejected::class, $this->client->search('Amore Infelice'));
    }

    /** LRCLIB matches on whole seconds, so the song's length is rounded rather than cut off. */
    public function testASignatureLookupSendsTheLengthRoundedToTheNearestSecond(): void
    {
        $client = $this->clientAnswering(self::answering(404), self::answering(404));

        $client->getBySignature('Avarice', 'Disturbed', 'Ten Thousand Fists', 176.657);
        $client->getBySignature('Avarice', 'Disturbed', 'Ten Thousand Fists', 176.4);

        $this->assertCount(2, $this->requests);
        $this->assertSame('177', $this->queryParameter($this->requests[0]['url'], 'duration'));
        $this->assertSame('176', $this->queryParameter($this->requests[1]['url'], 'duration'));
    }

    // --- search ---

    public function testSearchReturnsArrayOfResults(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            [
                'id' => 1,
                'trackName' => 'Still Alive',
                'artistName' => 'Portal',
                'albumName' => 'OST',
                'duration' => 200,
                'instrumental' => false,
                'plainLyrics' => 'This was a triumph',
                'syncedLyrics' => null,
            ],
            [
                'id' => 2,
                'trackName' => 'Want You Gone',
                'artistName' => 'Portal',
                'albumName' => 'OST 2',
                'duration' => 180,
                'instrumental' => false,
                'plainLyrics' => 'Well here we are again',
                'syncedLyrics' => '[00:01.00] Well here we are again',
            ],
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $results = $this->client->search('Portal');

        $this->assertCount(2, $results);
        $this->assertInstanceOf(LrclibSearchResult::class, $results[0]);
        $this->assertSame(1, $results[0]->id);
        $this->assertSame('Still Alive', $results[0]->trackName);
        $this->assertSame('Want You Gone', $results[1]->trackName);
        $this->assertSame('[00:01.00] Well here we are again', $results[1]->syncedLyrics);
    }

    public function testSearchReturnsEmptyArrayOn404(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->httpClient->method('request')->willReturn($response);

        $results = $this->client->search('nonexistent');

        $this->assertSame([], $results);
    }

    public function testSearchReportsAResponseThatIsNotAListAsUnavailable(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn(['error' => 'something']);

        $this->httpClient->method('request')->willReturn($response);

        $results = $this->client->search('test');

        $this->assertInstanceOf(LrclibUnavailable::class, $results);
    }

    // --- Error handling ---

    public function testLogsWarningAndReportsUnavailableOnHttp500Error(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createClient();

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);

        $this->httpClient->method('request')->willReturn($response);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignature('Song', 'Artist', 'Album', 100.0);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
    }

    public function testReportsATransportErrorAsUnavailable(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createClient();

        // Simulate a transport-level error that Symfony wraps in an exception
        $this->httpClient->method('request')->willThrowException(
            new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused'),
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignature('Song', 'Artist', 'Album', 100.0);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
    }

    /** A stalled LRCLIB cannot hold a request open: every call is bounded, and a timeout is an outage. */
    public function testRequestsAreBoundedAndATimeoutIsReportedAsUnavailable(): void
    {
        $options = [];
        $client = $this->createClient(new MockHttpClient(static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
            $options[] = $requestOptions;

            return new MockResponse((static function (): \Generator {
                yield '';
            })());
        }));

        $this->assertInstanceOf(LrclibUnavailable::class, $client->getBySignature('Song', 'Artist', 'Album', 100.0));
        $this->assertInstanceOf(LrclibUnavailable::class, $client->getById(912345));
        $this->assertInstanceOf(LrclibUnavailable::class, $client->search('Still Alive'));

        $this->assertCount(3, $options);
        foreach ($options as $requestOptions) {
            $this->assertSame(10.0, (float) $requestOptions['timeout']);
            $this->assertSame(10.0, (float) $requestOptions['max_duration']);
        }
    }

    public function testReportsAnUnexpectedExceptionAsUnavailable(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createClient();

        $this->httpClient->method('request')->willThrowException(
            new \LogicException('Unexpected state'),
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignature('Song', 'Artist', 'Album', 100.0);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
    }

    public function testReportsANetworkErrorAsUnavailable(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createClient();

        $this->httpClient->method('request')->willThrowException(
            new \RuntimeException('Connection timed out'),
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignature('Song', 'Artist', 'Album', 100.0);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
    }

    public function testSearchReportsANetworkErrorAsUnavailable(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createClient();

        $this->httpClient->method('request')->willThrowException(
            new \RuntimeException('DNS failure'),
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $results = $this->client->search('test');

        $this->assertInstanceOf(LrclibUnavailable::class, $results);
    }

    // --- The pace every process shares ---

    /** @return iterable<string, array{\Closure(LrclibClient, LrclibWait): mixed}> */
    public static function calls(): iterable
    {
        yield 'signature lookup' => [static fn (LrclibClient $client, LrclibWait $wait): mixed => $client->getBySignature('Still Alive', 'GLaDOS', 'Portal', 175.0, $wait)];
        yield 'lookup by ID' => [static fn (LrclibClient $client, LrclibWait $wait): mixed => $client->getById(123, $wait)];
        yield 'search' => [static fn (LrclibClient $client, LrclibWait $wait): mixed => $client->search('Still Alive', $wait)];
    }

    /** @param \Closure(LrclibClient, LrclibWait): mixed $call */
    #[DataProvider('calls')]
    public function testEveryRequestTakesATurnUnderOneSharedKeyWithinItsCallersWait(\Closure $call): void
    {
        $client = $this->clientAnswering(self::answering(404), self::answering(404));

        $call($client, LrclibWait::Interactive);
        $call($client, LrclibWait::Queued);

        $this->assertCount(2, $this->requests);
        $this->assertSame(['lrclib.net', 'lrclib.net'], array_column($this->turns, 'key'));
        [$interactive, $queued] = array_column($this->turns, 'longestWait');
        $this->assertEqualsWithDelta(10.0, $interactive, 0.001);
        $this->assertEqualsWithDelta(30.0, $queued, 0.001);
    }

    public function testACallWhoseTurnLiesBeyondItsWaitIsUnavailableWithoutARequest(): void
    {
        // Other processes hold the turns of the next twelve seconds.
        $this->turnWaits[] = static fn (): float => 12.0;
        $client = $this->clientAnswering();

        $result = $client->search('Still Alive');

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
        $this->assertSame([], $this->requests);
        $this->assertEqualsWithDelta(10.0, $this->turns[0]['longestWait'], 0.001, 'A call waits ten seconds unless told otherwise.');
        $this->assertSame(0.0, $this->elapsed());
    }

    public function testALimiterThatCannotBeReachedAnswersUnavailableWithoutARequest(): void
    {
        $this->turnWaits[] = static fn (): float => throw new RateLimiterUnavailableException();
        $client = $this->clientAnswering();

        $this->assertInstanceOf(LrclibUnavailable::class, $client->getById(123));
        $this->assertSame([], $this->requests);
    }

    public function testAPauseThatCannotBeReadAnswersUnavailableWithoutARequest(): void
    {
        $this->pauseReadsFail = true;
        $client = $this->clientAnswering();

        $this->assertInstanceOf(LrclibUnavailable::class, $client->getById(123));
        $this->assertSame([], $this->requests);
        $this->assertSame([], $this->turns);
    }

    // --- Rate-limit answers ---

    public function testARateLimitAnswerNamingAWaitPausesEveryProcessAndTheRequestIsSentAgainAfterIt(): void
    {
        $this->logger = $this->recordingLogger();
        $client = $this->clientAnswering(self::rateLimited('20'), self::lyrics());

        $result = $client->getBySignature('Still Alive', 'GLaDOS', 'Portal', 175.0, LrclibWait::Queued);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertSame('This was a triumph', $result->plainLyrics);
        $this->assertSame([20], $this->pauses);
        $this->assertCount(2, $this->requests);
        $this->assertSame($this->requests[0]['url'], $this->requests[1]['url']);
        $this->assertSame([0.0, 20.0], array_column($this->requests, 'at'));
        $this->assertSame(['2026-11-15T12:00:20+00:00'], $this->pausesLogged());
        $this->assertSame(20, $this->pauseWarnings()[0]['pause_seconds']);
        $this->assertArrayNotHasKey('retry_after_seconds', $this->pauseWarnings()[0]);
    }

    /**
     * No answer stops the lookups for longer than an hour, whatever wait it names. A number
     * too large for an integer counts as the largest one.
     */
    #[TestWith(['3601', 3601])]
    #[TestWith(['31536000', 31536000])]
    #[TestWith(['99999999999999999999999', PHP_INT_MAX])]
    public function testANamedWaitAboveAnHourIsStoredAsAnHourAndTheWarningNamesBoth(string $retryAfter, int $named): void
    {
        $this->logger = $this->recordingLogger();
        $client = $this->clientAnswering(self::rateLimited($retryAfter));

        $result = $client->getById(123, LrclibWait::Queued);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
        $this->assertStringContainsString('paused', $result->reason);
        $this->assertSame([3600], $this->pauses);
        $this->assertCount(1, $this->requests);
        $this->assertSame(['2026-11-15T13:00:00+00:00'], $this->pausesLogged());
        $this->assertSame(3600, $this->pauseWarnings()[0]['pause_seconds']);
        $this->assertSame($named, $this->pauseWarnings()[0]['retry_after_seconds'] ?? null);
    }

    public function testANamedWaitOfAnHourIsStoredAsNamed(): void
    {
        $this->logger = $this->recordingLogger();
        $client = $this->clientAnswering(self::rateLimited('3600'));

        $client->getById(123, LrclibWait::Queued);

        $this->assertSame([3600], $this->pauses);
        $this->assertArrayNotHasKey('retry_after_seconds', $this->pauseWarnings()[0]);
    }

    /** Only whole seconds name a wait: a Retry-After that gives a date pauses as one that names none. */
    public function testARetryAfterGivingADateTakesTheFallbackPause(): void
    {
        // Ten minutes after the call starts.
        $client = $this->clientAnswering(self::rateLimited('Sun, 15 Nov 2026 12:10:00 GMT'), self::lyrics());

        $result = $client->getById(123);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertSame([5], $this->pauses);
        $this->assertSame([0.0, 5.0], array_column($this->requests, 'at'));
    }

    public function testANamedWaitOfZeroSecondsLeavesNoPauseInEffectAndTheRequestIsSentAgainAtOnce(): void
    {
        $client = $this->clientAnswering(self::rateLimited('0'), self::lyrics());

        $result = $client->getById(123);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertSame([0], $this->pauses);
        $this->assertSame(0.0, $this->secondsPaused());
        $this->assertSame([0.0, 0.0], array_column($this->requests, 'at'));
        $this->assertCount(2, $this->turns, 'The request sent again takes a turn of its own.');
    }

    public function testRateLimitAnswersNamingNoWaitPauseFiveThenFifteenSecondsAndTheThirdIsUnavailable(): void
    {
        $client = $this->clientAnswering(self::rateLimited(), self::rateLimited('soon'), self::rateLimited());

        $result = $client->getById(123, LrclibWait::Queued);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
        $this->assertSame([5, 15, 15], $this->pauses);
        $this->assertSame([0.0, 5.0, 20.0], array_column($this->requests, 'at'));
    }

    public function testAShorterPauseAskedForDuringALongerOneLeavesTheLongerOneInEffect(): void
    {
        $this->logger = $this->recordingLogger();
        $client = $this->clientAnswering(
            function (): MockResponse {
                // While the request is out, LRCLIB tells another process to wait 25 seconds.
                $this->anotherProcessPausesFor(25);

                return self::rateLimited('5');
            },
            self::lyrics(),
        );

        $result = $client->getById(123, LrclibWait::Queued);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertSame([5], $this->pauses);
        $this->assertSame([0.0, 25.0], array_column($this->requests, 'at'));
        $this->assertSame(['2026-11-15T12:00:25+00:00'], $this->pausesLogged());
    }

    public function testAServiceUnavailableAnswerNamingAWaitPausesAndIsSentAgain(): void
    {
        $client = $this->clientAnswering(self::answering(503, ['Retry-After' => '1']), self::lyrics());

        $result = $client->getById(123);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertSame([1], $this->pauses);
        $this->assertSame([0.0, 1.0], array_column($this->requests, 'at'));
    }

    public function testAServiceUnavailableAnswerNamingNoWaitIsUnavailableAtOnceWithoutAPause(): void
    {
        $client = $this->clientAnswering(self::answering(503));

        $result = $client->getById(123);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
        $this->assertSame('HTTP 503', $result->reason);
        $this->assertCount(1, $this->requests);
        $this->assertSame([], $this->pauses);
        $this->assertSame(0.0, $this->elapsed());
    }

    #[TestWith([LrclibWait::Interactive])]
    #[TestWith([LrclibWait::Queued])]
    public function testACallIsUnavailableWithoutARequestWhileThePauseOutlastsItsWait(LrclibWait $wait): void
    {
        $this->anotherProcessPausesFor(60);
        $client = $this->clientAnswering();

        $result = $client->search('Still Alive', $wait);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
        $this->assertStringContainsString('paused', $result->reason);
        $this->assertSame([], $this->requests);
        $this->assertSame([], $this->turns, 'A call that cannot send spends no turn.');
        $this->assertSame(0.0, $this->elapsed());
    }

    public function testAPauseOfTwentySecondsEndsAnInteractiveCallAndHoldsAQueuedOneUntilItIsOver(): void
    {
        $this->anotherProcessPausesFor(20);
        $client = $this->clientAnswering(self::lyrics());

        $interactive = $client->getById(123, LrclibWait::Interactive);

        $this->assertInstanceOf(LrclibUnavailable::class, $interactive);
        $this->assertStringContainsString('paused', $interactive->reason);
        $this->assertSame([], $this->requests);

        $queued = $client->getById(123, LrclibWait::Queued);

        $this->assertInstanceOf(LrclibResult::class, $queued);
        $this->assertSame([20.0], array_column($this->requests, 'at'));
    }

    public function testAPauseStoredWhileTheCallWaitsForItsTurnIsWaitedOutBeforeTheRequest(): void
    {
        // The turn is a second and a half away, and meanwhile LRCLIB tells another process to wait six seconds.
        $this->turnWaits[] = function (): float {
            $this->anotherProcessPausesFor(6);

            return 1.5;
        };
        $client = $this->clientAnswering(self::lyrics());

        $result = $client->getById(123);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertCount(1, $this->requests);
        $this->assertGreaterThanOrEqual(6.0, $this->requests[0]['at']);
        $this->assertCount(2, $this->turns, 'The request goes out on a fresh turn after the pause.');
    }

    public function testAResendWhosePauseEndsAfterTheCallsWaitIsUnavailableInstead(): void
    {
        $client = $this->clientAnswering(self::rateLimited('20'));

        $result = $client->getById(123);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
        $this->assertStringContainsString('paused', $result->reason);
        $this->assertSame([20], $this->pauses);
        $this->assertCount(1, $this->requests);
        $this->assertSame(0.0, $this->elapsed());
    }

    public function testACallKeepsOneDeadlineThroughItsResends(): void
    {
        $client = $this->clientAnswering(self::rateLimited('6'), self::rateLimited('6'));

        $result = $client->getById(123);

        $this->assertInstanceOf(LrclibUnavailable::class, $result);
        $this->assertSame([6, 6], $this->pauses);
        // The second pause ends twelve seconds into a call that waits ten.
        $this->assertSame([0.0, 6.0], array_column($this->requests, 'at'));
        $this->assertSame(6.0, $this->elapsed());
    }

    public function testARateLimitAnswerWhosePauseCannotBeStoredIsUnavailable(): void
    {
        $this->pauseWritesFail = true;
        $client = $this->clientAnswering(self::rateLimited('1'));

        $this->assertInstanceOf(LrclibUnavailable::class, $client->getById(123));
        $this->assertCount(1, $this->requests);
    }

    // --- Identification ---

    /** @param \Closure(LrclibClient, LrclibWait): mixed $call */
    #[DataProvider('calls')]
    public function testEveryRequestNamesBaanderItsVersionAndItsContactAddress(\Closure $call): void
    {
        $client = $this->clientAnswering(self::answering(404));

        $call($client, LrclibWait::Interactive);

        $this->assertCount(1, $this->requests);
        $this->assertContains('User-Agent: ' . self::USER_AGENT, $this->requests[0]['headers']);
    }

    // --- Instrumental tracks ---

    public function testHandlesInstrumentalTrackWithNullLyrics(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'id' => 777,
            'trackName' => 'Intro',
            'artistName' => 'Artist',
            'albumName' => 'Album',
            'duration' => 60,
            'instrumental' => true,
            'plainLyrics' => null,
            'syncedLyrics' => null,
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->client->getBySignature('Intro', 'Artist', 'Album', 60.0);

        $this->assertInstanceOf(LrclibResult::class, $result);
        $this->assertTrue($result->instrumental);
        $this->assertNull($result->plainLyrics);
        $this->assertNull($result->syncedLyrics);
    }

    // --- DTO mapping ---

    public function testLrclibResultFromApiResponseMapsAllFields(): void
    {
        $data = [
            'id' => 3396226,
            'trackName' => 'I Want to Live',
            'artistName' => 'Borislav Slavov',
            'albumName' => "Baldur's Gate 3 (Original Game Soundtrack)",
            'duration' => 233,
            'instrumental' => false,
            'plainLyrics' => "I feel your breath upon my neck\n",
            'syncedLyrics' => "[00:17.12] I feel your breath upon my neck\n",
        ];

        $result = LrclibResult::fromApiResponse($data);

        $this->assertSame(3396226, $result->id);
        $this->assertSame('I Want to Live', $result->trackName);
        $this->assertSame('Borislav Slavov', $result->artistName);
        $this->assertSame("Baldur's Gate 3 (Original Game Soundtrack)", $result->albumName);
        $this->assertSame(233.0, $result->duration);
        $this->assertFalse($result->instrumental);
        $this->assertSame("I feel your breath upon my neck\n", $result->plainLyrics);
        $this->assertSame("[00:17.12] I feel your breath upon my neck\n", $result->syncedLyrics);
    }

    public function testLrclibSearchResultFromApiResponseMapsAllFields(): void
    {
        $data = [
            'id' => 100,
            'trackName' => 'Search Hit',
            'artistName' => 'Search Artist',
            'albumName' => 'Search Album',
            'duration' => 250,
            'instrumental' => false,
            'plainLyrics' => 'lyrics here',
            'syncedLyrics' => '[00:10.00] lyrics here',
        ];

        $result = LrclibSearchResult::fromApiResponse($data);

        $this->assertSame(100, $result->id);
        $this->assertSame('Search Hit', $result->trackName);
        $this->assertSame(250.0, $result->duration);
        $this->assertSame('lyrics here', $result->plainLyrics);
        $this->assertSame('[00:10.00] lyrics here', $result->syncedLyrics);
    }

    public function testLrclibSearchResultFromApiResponseHandlesNullDuration(): void
    {
        $data = [
            'id' => 200,
            'trackName' => 'No Duration',
            'artistName' => 'Artist',
            'albumName' => 'Album',
            'instrumental' => true,
            'plainLyrics' => null,
            'syncedLyrics' => null,
        ];

        $result = LrclibSearchResult::fromApiResponse($data);

        $this->assertNull($result->duration);
        $this->assertTrue($result->instrumental);
    }

    /**
     * A client whose requests are answered in order, each recorded with the time it was sent at.
     * A closure answers when its request is sent, so it can act as another process meanwhile.
     *
     * @param MockResponse|\Closure(): MockResponse ...$answers
     */
    private function clientAnswering(MockResponse|\Closure ...$answers): LrclibClient
    {
        return $this->createClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->requests[] = ['url' => $url, 'at' => $this->elapsed(), 'headers' => $options['headers']];
            $answer = array_shift($answers) ?? throw new \LogicException('No response is queued for ' . $url);

            return $answer instanceof \Closure ? $answer() : $answer;
        }));
    }

    private static function lyrics(): MockResponse
    {
        return new MockResponse(json_encode([
            'id' => 123,
            'trackName' => 'Still Alive',
            'artistName' => 'GLaDOS',
            'albumName' => 'Portal',
            'duration' => 175,
            'instrumental' => false,
            'plainLyrics' => 'This was a triumph',
            'syncedLyrics' => null,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $headers */
    private static function answering(int $status, array $headers = []): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => $headers]);
    }

    /** LRCLIB's answer to a client that sends too fast, naming the wait when one is given. */
    private static function rateLimited(?string $retryAfter = null): MockResponse
    {
        return self::answering(429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter]);
    }

    /** The pause every process honours, kept in this test on the client's clock. */
    private function pause(): LrclibPauseInterface
    {
        return new class($this->secondsPaused(...), $this->storePause(...)) implements LrclibPauseInterface {
            /**
             * @param \Closure(): float    $secondsLeft
             * @param \Closure(int): float $pauseFor
             */
            public function __construct(private readonly \Closure $secondsLeft, private readonly \Closure $pauseFor)
            {
            }

            public function secondsLeft(): float
            {
                return ($this->secondsLeft)();
            }

            public function pauseFor(int $seconds): float
            {
                return ($this->pauseFor)($seconds);
            }
        };
    }

    private function secondsPaused(): float
    {
        if ($this->pauseReadsFail) {
            throw new RateLimiterUnavailableException();
        }

        return max(0, $this->pausedUntil - $this->nowMicroseconds()) / 1e6;
    }

    /** Records a pause the client asks for and stores it as the shared store does. */
    private function storePause(int $seconds): float
    {
        if ($this->pauseWritesFail) {
            throw new RateLimiterUnavailableException();
        }
        $this->pauses[] = $seconds;
        $this->anotherProcessPausesFor($seconds);

        return $this->secondsPaused();
    }

    /** A pause stored from now on; one in effect that ends later stays as it is. */
    private function anotherProcessPausesFor(int $seconds): void
    {
        $this->pausedUntil = max($this->pausedUntil, $this->nowMicroseconds() + $seconds * 1_000_000);
    }

    /** A limiter under which every turn comes at once unless a test queued a wait for it. */
    private function limiter(): RateLimiterFactoryInterface
    {
        return new class($this->takeTurn(...)) implements RateLimiterFactoryInterface {
            /** @param \Closure(?string, ?float): float $takeTurn */
            public function __construct(private readonly \Closure $takeTurn)
            {
            }

            public function create(?string $key = null): LimiterInterface
            {
                return new class($this->takeTurn, $key) implements LimiterInterface {
                    /** @param \Closure(?string, ?float): float $takeTurn */
                    public function __construct(private readonly \Closure $takeTurn, private readonly ?string $key)
                    {
                    }

                    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
                    {
                        $wait = ($this->takeTurn)($this->key, $maxTime);

                        return new Reservation(microtime(true) + $wait, new RateLimit(0, new \DateTimeImmutable(), true, 1));
                    }

                    public function consume(int $tokens = 1): RateLimit
                    {
                        throw new \LogicException('LRCLIB requests reserve a turn.');
                    }

                    public function reset(): void
                    {
                    }
                };
            }
        };
    }

    /** Records a turn and answers the seconds until it, or refuses one further away than the caller waits. */
    private function takeTurn(?string $key, ?float $longestWait): float
    {
        $this->turns[] = ['key' => $key, 'longestWait' => $longestWait];
        $wait = (array_shift($this->turnWaits) ?? static fn (): float => 0.0)();
        if ($longestWait !== null && $wait > $longestWait) {
            throw new MaxWaitDurationExceededException('The turn is further away than the caller waits.', new RateLimit(0, new \DateTimeImmutable(), false, 1));
        }

        return $wait;
    }

    private function recordingLogger(): LoggerInterface
    {
        $record = function (string $level, string $message, array $context): void {
            $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };

        return new class($record) extends AbstractLogger {
            /** @param \Closure(string, string, array<array-key, mixed>): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)((string) $level, (string) $message, $context);
            }
        };
    }

    /** @return list<mixed> the end time of every pause logged as a warning */
    private function pausesLogged(): array
    {
        return array_column($this->pauseWarnings(), 'paused_until');
    }

    /** @return list<array<array-key, mixed>> the context of every warning that logs a pause */
    private function pauseWarnings(): array
    {
        $warnings = array_filter(
            $this->logs,
            static fn (array $log): bool => $log['level'] === LogLevel::WARNING && isset($log['context']['paused_until']),
        );

        return array_column($warnings, 'context');
    }

    /** Seconds the test's clock has moved since the test started. */
    private function elapsed(): float
    {
        return ($this->nowMicroseconds() - $this->startedAt) / 1e6;
    }

    private function nowMicroseconds(): int
    {
        return (int) $this->clock->now()->format('Uu');
    }

    private function answer(int $status): ResponseInterface&Stub
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);

        return $response;
    }

    private function queryParameter(string $url, string $name): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $value = $query[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
