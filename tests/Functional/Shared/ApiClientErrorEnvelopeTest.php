<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Application\Http\BaanderHeader;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A client error the router or the framework raises on an API path answers in the shared error
 * envelope, with a message for its status in the request's language instead of the exception's
 * own text. A path outside the API keeps the framework's error page.
 */
final class ApiClientErrorEnvelopeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function apiPathsNoRouteMatches(): iterable
    {
        yield 'unknown endpoint' => ['/api/no-such-endpoint'];
        yield 'percent-encoded unknown endpoint' => ['/%61pi/no-such-endpoint'];
        yield 'public ID that fails the route requirement' => ['/api/people/not-a-public-id'];
    }

    #[DataProvider('apiPathsNoRouteMatches')]
    public function testAnApiPathNoRouteMatchesIsANotFoundInTheErrorEnvelope(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertSame(
            ['error' => ['message' => 'Resource not found.', 'code' => 404]],
            $this->assertJsonResponse($this->client->getResponse(), 404),
        );
    }

    public function testAMethodTheRouteDoesNotAllowIsA405InTheRequestsLanguageWithItsAllowHeader(): void
    {
        $this->client->request('DELETE', '/api/libraries', server: ['HTTP_ACCEPT_LANGUAGE' => 'da']);
        $response = $this->client->getResponse();

        self::assertSame(
            ['error' => ['message' => 'Metoden er ikke tilladt for denne ressource.', 'code' => 405]],
            $this->assertJsonResponse($response, 405),
        );
        self::assertSame('GET, POST', $response->headers->get('Allow'));
    }

    public function testARequestBodyThatIsNotJsonIsABadRequestInTheErrorEnvelope(): void
    {
        $this->client->request('POST', '/api/libraries', server: [
            'CONTENT_TYPE' => 'application/json',
            BaanderHeader::TestUserId->serverKey() => $this->createAdminUser()->getId()->toString(),
        ], content: '{"name": ');

        self::assertSame(
            ['error' => ['message' => 'The request is malformed.', 'code' => 400]],
            $this->assertJsonResponse($this->client->getResponse(), 400),
        );
    }

    public function testAPathOutsideTheApiKeepsTheFrameworksErrorPage(): void
    {
        $this->client->request('POST', '/library');
        $response = $this->client->getResponse();

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }
}
