<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Log\SymfonyAccessLogDataMap;
use Symfony\Component\HttpFoundation\{Request, Response};

final class BundleAccessLogTrustTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string, string, string}> */
    public static function requests(): iterable
    {
        yield 'untrusted forwarding' => [[], '192.0.2.5', '203.0.113.8', '192.0.2.5'];
        yield 'malformed untrusted forwarding' => [[], '192.0.2.5', 'invalid', '192.0.2.5'];
        yield 'trusted proxy chain' => [['10.0.0.0/24'], '10.0.0.10', '203.0.113.8, 10.0.0.9', '203.0.113.8'];
    }

    /** @param list<string> $proxies */
    #[DataProvider('requests')]
    public function testClientAddressUsesTheConfiguredProxyTrust(array $proxies, string $remote, string $forwarded, string $expected): void
    {
        $originalProxies = Request::getTrustedProxies();
        $originalHeaders = Request::getTrustedHeaderSet();
        try {
            Request::setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_FOR);
            $request = Request::create('https://baander.app/library', server: [
                'REMOTE_ADDR' => $remote,
                'HTTP_X_REAL_IP' => '198.51.100.4',
                'HTTP_X_FORWARDED_FOR' => $forwarded,
            ]);
            $map = new SymfonyAccessLogDataMap()->setRequestResponse($request, new Response());

            self::assertSame($expected, $map->getClientIp());
        } finally {
            Request::setTrustedProxies($originalProxies, $originalHeaders);
        }
    }
}
