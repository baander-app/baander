<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Shared\Application\Http\BaanderHeader;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Exhausts real limiter buckets and resets them afterwards, so the Redis-backed
 * state never leaks into other functional tests.
 */
abstract class RateLimitTestCase extends TestCase
{
    /** @var list<array{string, string}> */
    private array $exhausted = [];

    protected function tearDown(): void
    {
        foreach ($this->exhausted as [$name, $key]) {
            $this->limiter($name)->create($key)->reset();
        }
        $this->exhausted = [];

        parent::tearDown();
    }

    protected function exhaust(string $name, string $key): void
    {
        $this->exhausted[] = [$name, $key];
        // The test environment sets every limit to 10000.
        self::assertTrue($this->limiter($name)->create($key)->consume(10000)->isAccepted(), sprintf('Limiter "%s" was already in use for "%s".', $name, $key));
    }

    protected function limiter(string $name): RateLimiterFactoryInterface
    {
        $factory = static::getContainer()->get('limiter.' . $name);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }

    /** @param array<string, mixed>|null $content */
    protected function requestFrom(string $ip, string $method, string $uri, ?string $userId = null, ?array $content = null): Response
    {
        $server = ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'];
        if ($userId !== null) {
            $server[BaanderHeader::TestUserId->serverKey()] = $userId;
        }

        $this->client->request($method, $uri, [], [], $server, $content === null ? null : json_encode($content, JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    protected function assertTooManyRequests(Response $response): void
    {
        self::assertSame(429, $response->getStatusCode(), (string) $response->getContent());

        $retryAfter = $response->headers->get('Retry-After');
        self::assertNotNull($retryAfter, 'A 429 response must carry Retry-After.');
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', $retryAfter);

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(429, $body['status'] ?? null, (string) $response->getContent());
    }
}
