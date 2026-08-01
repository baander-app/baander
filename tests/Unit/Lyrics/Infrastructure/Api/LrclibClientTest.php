<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Infrastructure\Api;

use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Infrastructure\Api\LrclibClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class LrclibClientTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private LrclibClient $client;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = new LrclibClient(
            $this->httpClient,
            $this->logger,
            'https://lrclib.net',
        );
    }

    // --- getBySignatureCached ---

    public function testGetBySignatureCachedReturnsResult(): void
    {
        $response = $this->createMock(ResponseInterface::class);
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

        $result = $this->client->getBySignatureCached('Test Song', 'Test Artist', 'Test Album', 200.0);

        $this->assertNotNull($result);
        $this->assertSame(123, $result->id);
        $this->assertSame('Test Song', $result->trackName);
        $this->assertSame('Test Artist', $result->artistName);
        $this->assertSame('Test Album', $result->albumName);
        $this->assertSame(200.0, $result->duration);
        $this->assertFalse($result->instrumental);
        $this->assertSame('Some lyrics', $result->plainLyrics);
        $this->assertSame('[00:01.00] Some lyrics', $result->syncedLyrics);
    }

    public function testGetBySignatureCachedReturnsNullOn404(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->client->getBySignatureCached('Missing', 'Artist', 'Album', 100.0);

        $this->assertNull($result);
    }

    // --- getBySignature (cached-first + fallback) ---

    public function testGetBySignatureReturnsResultFromFullEndpoint(): void
    {
        $fullResponse = $this->createMock(ResponseInterface::class);
        $fullResponse->method('getStatusCode')->willReturn(200);
        $fullResponse->method('toArray')->willReturn([
            'id' => 42,
            'trackName' => 'Full Song',
            'artistName' => 'Artist',
            'albumName' => 'Album',
            'duration' => 180,
            'instrumental' => false,
            'plainLyrics' => 'full lyrics',
            'syncedLyrics' => null,
        ]);

        // Only one call expected — getBySignature hits /api/get directly
        $this->httpClient->expects($this->once())->method('request')->willReturn($fullResponse);

        $result = $this->client->getBySignature('Full Song', 'Artist', 'Album', 180.0);

        $this->assertNotNull($result);
        $this->assertSame(42, $result->id);
        $this->assertSame('full lyrics', $result->plainLyrics);
    }

    public function testGetBySignatureReturnsNullOn404(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->httpClient->expects($this->once())->method('request')->willReturn($response);

        $result = $this->client->getBySignature('Unknown', 'Artist', 'Album', 100.0);

        $this->assertNull($result);
    }

    public function testGetBySignatureReturnsNullOnHttpError(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);

        $this->httpClient->expects($this->once())->method('request')->willReturn($response);
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignature('Song', 'Artist', 'Album', 100.0);

        $this->assertNull($result);
    }

    // --- getById ---

    public function testGetByIdReturnsResult(): void
    {
        $response = $this->createMock(ResponseInterface::class);
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

        $this->assertNotNull($result);
        $this->assertSame(555, $result->id);
        $this->assertSame('ById Song', $result->trackName);
    }

    public function testGetByIdReturnsNullOn404(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->client->getById(99999);

        $this->assertNull($result);
    }

    // --- search ---

    public function testSearchReturnsArrayOfResults(): void
    {
        $response = $this->createMock(ResponseInterface::class);
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
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->httpClient->method('request')->willReturn($response);

        $results = $this->client->search('nonexistent');

        $this->assertSame([], $results);
    }

    public function testSearchReturnsEmptyArrayWhenResponseIsNotList(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn(['error' => 'something']);

        $this->httpClient->method('request')->willReturn($response);

        $results = $this->client->search('test');

        $this->assertSame([], $results);
    }

    // --- Error handling ---

    public function testLogsWarningOnHttp500Error(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);

        $this->httpClient->method('request')->willReturn($response);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignatureCached('Song', 'Artist', 'Album', 100.0);

        $this->assertNull($result);
    }

    public function testReturnsNullOnHttpException(): void
    {
        // Simulate a transport-level error that Symfony wraps in an exception
        $this->httpClient->method('request')->willThrowException(
            new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused'),
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignatureCached('Song', 'Artist', 'Album', 100.0);

        $this->assertNull($result);
    }

    public function testReturnsNullOnGenericException(): void
    {
        $this->httpClient->method('request')->willThrowException(
            new \LogicException('Unexpected state'),
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignatureCached('Song', 'Artist', 'Album', 100.0);

        $this->assertNull($result);
    }

    public function testReturnsNullOnNetworkError(): void
    {
        $this->httpClient->method('request')->willThrowException(
            new \RuntimeException('Connection timed out'),
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->client->getBySignatureCached('Song', 'Artist', 'Album', 100.0);

        $this->assertNull($result);
    }

    public function testSearchReturnsEmptyArrayOnNetworkError(): void
    {
        $this->httpClient->method('request')->willThrowException(
            new \RuntimeException('DNS failure'),
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $results = $this->client->search('test');

        $this->assertSame([], $results);
    }

    // --- Instrumental tracks ---

    public function testHandlesInstrumentalTrackWithNullLyrics(): void
    {
        $response = $this->createMock(ResponseInterface::class);
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

        $result = $this->client->getBySignatureCached('Intro', 'Artist', 'Album', 60.0);

        $this->assertNotNull($result);
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
}
