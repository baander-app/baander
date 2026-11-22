<?php

declare(strict_types=1);

namespace App\Tests\Unit\SwooleBundle\Server\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Server\Config\Socket;
use Symfony\Component\Yaml\Yaml;

final class SocketTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function listenAddresses(): iterable
    {
        yield 'IPv4 wildcard' => ['0.0.0.0', 'tcp', SWOOLE_SOCK_TCP];
        yield 'IPv4 loopback' => ['127.0.0.1', 'tcp', SWOOLE_SOCK_TCP];
        // Swoole refuses to listen on an IPv6 address with an IPv4 socket.
        yield 'IPv6 wildcard, which also takes IPv4 clients' => ['::', 'tcp', SWOOLE_SOCK_TCP6];
        yield 'IPv6 loopback' => ['::1', 'tcp', SWOOLE_SOCK_TCP6];
        yield 'IPv6 UDP' => ['::', 'udp', SWOOLE_SOCK_UDP6];
        yield 'explicit IPv6 socket' => ['::', 'tcp_ipv6', SWOOLE_SOCK_TCP6];
    }

    #[DataProvider('listenAddresses')]
    public function testTheListenAddressChoosesTheSocketFamily(string $host, string $type, int $expected): void
    {
        self::assertSame($expected, new Socket($host, 9501, $type)->type());
    }

    public function testAHostOverrideCarriesItsFamily(): void
    {
        $configured = new Socket('0.0.0.0', 9501);

        self::assertSame(SWOOLE_SOCK_TCP6, $configured->withHost('::')->type());
        self::assertSame(SWOOLE_SOCK_TCP, $configured->withHost('::')->withHost('0.0.0.0')->type());
    }

    public function testTheAddressPortOfAnIpv6HostIsBracketed(): void
    {
        self::assertSame('[::]:9501', new Socket('::', 9501)->addressPort());
        self::assertSame('0.0.0.0:9501', new Socket('0.0.0.0', 9501)->addressPort());
    }

    public function testTheApplicationListensOnIpv4UnlessItsHostIsOverridden(): void
    {
        // An IPv4 listener needs no IPv6 support from the host's kernel; a dual-stack deployment passes --host ::.
        $swoole = Yaml::parseFile(dirname(__DIR__, 5) . '/config/packages/swoole.yaml');

        self::assertSame('0.0.0.0', $swoole['swoole']['http_server']['host']);
        self::assertSame('tcp', $swoole['swoole']['http_server']['socket_type']);
    }
}
