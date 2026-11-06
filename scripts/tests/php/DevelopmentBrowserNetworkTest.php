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

    #[DataProvider('rejectedNetworkProvider')]
    public function testOtherNetworksAreRejected(string $network): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrowserCheckpoint::network($network);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedNetworkProvider(): iterable
    {
        yield 'a named bridge network' => ['bridge'];
        yield 'an empty container name' => ['container:'];
        yield 'a container name with a space' => ['container:web name'];
        yield 'a leading separator' => ['container:-web'];
    }
}
