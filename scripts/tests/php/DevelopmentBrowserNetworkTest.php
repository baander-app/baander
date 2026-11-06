<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Acceptance\BrowserCheckpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentBrowserNetworkTest extends TestCase
{
    public function testTheHostNetworkIsTheDefault(): void
    {
        self::assertSame('host', BrowserCheckpoint::network(''));
        self::assertSame('host', BrowserCheckpoint::network('host'));
    }

    public function testAContainerNamespaceIsAccepted(): void
    {
        self::assertSame('container:baander-worker-web-1a2b', BrowserCheckpoint::network('container:baander-worker-web-1a2b'));
    }

    public function testANamedDockerNetworkIsAccepted(): void
    {
        self::assertSame('baander-worker-operator-1793989271-435080', BrowserCheckpoint::network('baander-worker-operator-1793989271-435080'));
    }

    #[DataProvider('rejectedNetworkProvider')]
    public function testOtherNetworksAreRejected(string $network): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrowserCheckpoint::network($network);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedNetworkProvider(): iterable
    {
        yield 'an empty container name' => ['container:'];
        yield 'a container name with a space' => ['container:web name'];
        yield 'a leading separator' => ['container:-web'];
        yield 'a network name with a space' => ['drill network'];
        yield 'a network name with a leading separator' => ['-drill'];
    }

    public function testAnUpstreamIsOptional(): void
    {
        self::assertSame('', BrowserCheckpoint::upstream(''));
        self::assertSame('web:9501', BrowserCheckpoint::upstream('web:9501'));
    }

    #[DataProvider('rejectedUpstreamProvider')]
    public function testOtherUpstreamsAreRejected(string $upstream): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrowserCheckpoint::upstream($upstream);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedUpstreamProvider(): iterable
    {
        yield 'a host without a port' => ['web'];
        yield 'a port of zero' => ['web:0'];
        yield 'a scheme' => ['http://web:9501'];
        yield 'a host with a space' => ['web host:9501'];
    }
}
