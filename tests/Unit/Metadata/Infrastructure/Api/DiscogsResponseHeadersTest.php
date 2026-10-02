<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api;

use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class DiscogsResponseHeadersTest extends TestCase
{
    public function testRateLimitStatusAndHeaderComeFromTheCurrentResponse(): void
    {
        $adapter = new DiscogsAdapter('test-token', new NullLogger(), new JsonEncoder());
        $headers = ['HTTP/1.1 429 Too Many Requests', 'retry-after: 5'];
        self::assertSame(429, (new \ReflectionMethod($adapter, 'extractStatusCode'))->invoke($adapter, $headers));
        self::assertSame('5', (new \ReflectionMethod($adapter, 'extractHeader'))->invoke($adapter, 'Retry-After', $headers));
    }

    public function testRedirectHeadersDoNotOverrideTheFinalResponse(): void
    {
        $adapter = new DiscogsAdapter('test-token', new NullLogger(), new JsonEncoder());
        $headers = ['HTTP/1.1 302 Found', 'Retry-After: 9', 'HTTP/1.1 200 OK'];
        self::assertSame(200, (new \ReflectionMethod($adapter, 'extractStatusCode'))->invoke($adapter, $headers));
        self::assertNull((new \ReflectionMethod($adapter, 'extractHeader'))->invoke($adapter, 'Retry-After', $headers));
    }
}
