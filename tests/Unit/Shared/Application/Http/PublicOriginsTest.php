<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Http;

use App\Shared\Application\Http\PublicOrigins;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PublicOriginsTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    public function testARequestOnAListedOriginRendersThatOrigin(): void
    {
        $origins = new PublicOrigins('https://baander.app', ['https://192.168.1.20:8443']);

        self::assertSame('https://baander.app', $origins->forRequest(Request::create('https://baander.app/library')));
        self::assertSame('https://192.168.1.20:8443', $origins->forRequest(Request::create('https://192.168.1.20:8443/library')));
    }

    public function testAnUnlistedHostRendersThePrimaryOrigin(): void
    {
        $origins = new PublicOrigins('https://baander.app', ['https://192.168.1.20:8443']);

        self::assertSame('https://baander.app', $origins->forRequest(Request::create('https://other.baander.app/library')));
    }

    public function testAForwardedHostFromATrustedProxyChoosesOnlyAmongTheListedOrigins(): void
    {
        $origins = new PublicOrigins('https://baander.app', ['https://192.168.1.20:8443']);
        Request::setTrustedProxies(
            ['10.0.0.1'],
            Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT,
        );
        $forwarded = static fn (string $host): Request => Request::create('http://web:9501/library', server: [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => $host,
        ]);

        self::assertSame('https://192.168.1.20:8443', $origins->forRequest($forwarded('192.168.1.20:8443')));
        self::assertSame('https://baander.app', $origins->forRequest($forwarded('other.baander.app')));
    }
}
