<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Auth\Infrastructure\Security\ApiRateLimitListener;
use Assert\Assertion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command\ServerStartCommand;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\Configuration;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\DefaultRequestFactory;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SetRequestRuntimeConfiguration;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\TrustAllProxiesRequestHandler;
use SwooleBundle\SwooleBundle\Server\RequestHandler\RequestHandler;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Yaml\Yaml;

final class ProxyTrustTest extends TestCase
{
    /** @var list<string> */
    private array $previousProxies;
    private int $previousHeaders;

    protected function setUp(): void
    {
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaders = Request::getTrustedHeaderSet();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaders);
    }

    public function testBothServersUseTheSameExplicitTrustSetting(): void
    {
        $root = dirname(__DIR__, 5);
        $framework = Yaml::parseFile($root . '/config/packages/framework.yaml');
        $swoole = Yaml::parseFile($root . '/config/packages/swoole.yaml');
        $dev = Yaml::parseFile($root . '/config/packages/dev/framework.yaml');
        self::assertSame('%env(csv:TRUSTED_PROXIES)%', $framework['framework']['trusted_proxies']);
        self::assertSame('%env(TRUSTED_PROXIES)%', $swoole['swoole']['http_server']['trusted_proxies']);
        self::assertFalse($swoole['swoole']['http_server']['services']['trust_all_proxies_handler']);
        self::assertArrayNotHasKey('trusted_proxies', $dev['when@dev']['framework']);
        self::assertSame('', $framework['parameters']['env(TRUSTED_PROXIES)']);
    }

    #[DataProvider('proxyEnvironmentValues')]
    public function testSwooleEnvResolvesToCommandParsableStrings(string $value): void
    {
        $previous = $_ENV['TRUSTED_PROXIES'] ?? null;
        $_ENV['TRUSTED_PROXIES'] = $value;
        try {
            $container = new ContainerBuilder();
            $container->setParameter('env(TRUSTED_PROXIES)', '');
            $swoole = Yaml::parseFile(dirname(__DIR__, 5) . '/config/packages/swoole.yaml');
            $placeholder = $container->getParameterBag()->resolveValue($swoole['swoole']['http_server']['trusted_proxies']);
            $config = (new Processor())->processConfiguration(
                new Configuration(new TreeBuilder('swoole')),
                [['http_server' => ['trusted_proxies' => $placeholder]]],
            );
            $resolved = $container->resolveEnvPlaceholders($config['http_server']['trusted_proxies'], true);
            Assertion::allString($resolved);
            $command = (new \ReflectionClass(ServerStartCommand::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod($command, 'decodeSet');
            $decoded = $method->invoke($command, $resolved);
            self::assertSame($value === '' ? [] : explode(',', $value), $decoded);
            self::assertSame($container->resolveEnvPlaceholders('%env(csv:TRUSTED_PROXIES)%', true), $decoded);
        } finally {
            if ($previous === null) {
                unset($_ENV['TRUSTED_PROXIES']);
            } else {
                $_ENV['TRUSTED_PROXIES'] = $previous;
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function proxyEnvironmentValues(): iterable
    {
        yield 'no trusted proxies' => [''];
        yield 'single proxy' => ['192.0.2.10'];
        yield 'multiple addresses and CIDRs' => ['192.0.2.10,2001:db8:1::/64'];
    }

    /** @return iterable<string, array{list<string>, string, string, string}> */
    public static function clients(): iterable
    {
        yield 'direct IPv4 spoof' => [[], '172.18.0.10', '198.51.100.20', '172.18.0.10'];
        yield 'direct IPv6 spoof' => [[], '2001:db8::20', '198.51.100.20', '2001:db8::20'];
        yield 'trusted proxy chain' => [['192.0.2.10', '192.0.2.11'], '192.0.2.10', '198.51.100.20, 192.0.2.11', '198.51.100.20'];
        yield 'untrusted middle hop' => [['192.0.2.10'], '192.0.2.10', '198.51.100.20, 192.0.2.11', '192.0.2.11'];
        yield 'trusted IPv6 proxy' => [['2001:db8:1::/64'], '2001:db8:1::10', '2001:db8:2::20', '2001:db8:2::20'];
        yield 'malformed address ignored' => [['192.0.2.10'], '192.0.2.10', 'invalid, 198.51.100.20', '198.51.100.20'];
        yield 'entirely malformed chain' => [['192.0.2.10'], '192.0.2.10', 'invalid, unknown', '192.0.2.10'];
    }

    /** @param list<string> $proxies */
    #[DataProvider('clients')]
    public function testRuntimeResolvesOnlyTrustedForwardedClients(array $proxies, string $peer, string $forwarded, string $expected): void
    {
        (new SetRequestRuntimeConfiguration())->boot(['trustedProxies' => $proxies]);
        $request = Request::create('http://api.baander.app/api/auth/me', server: [
            'REMOTE_ADDR' => $peer,
            'HTTP_X_FORWARDED_FOR' => $forwarded,
        ]);
        self::assertSame($expected, $request->getClientIp());
    }

    #[DataProvider('trustedUrlCases')]
    public function testForwardedUrlComesFromTrustedPeerOnly(bool $trusted, string $forwardedHost, ?string $forwardedPort, string $externalOrigin): void
    {
        (new SetRequestRuntimeConfiguration())->boot(['trustedProxies' => $trusted ? ['192.0.2.10'] : []]);
        $server = [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_HOST' => $forwardedHost,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        if ($forwardedPort !== null) {
            $server['HTTP_X_FORWARDED_PORT'] = $forwardedPort;
        }
        $request = Request::create('http://internal.baander.app/api/auth/me?ignored=yes', server: $server);

        self::assertSame($trusted ? $externalOrigin : 'http://internal.baander.app', $request->getSchemeAndHttpHost());
    }

    public function testChangingSpoofedAddressCannotResetTheApiBudget(): void
    {
        (new SetRequestRuntimeConfiguration())->boot(['trustedProxies' => []]);
        $factory = new RateLimiterFactory(['id' => 'anonymous_api', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'], new InMemoryStorage());
        $listener = new ApiRateLimitListener($factory, $factory, new TokenStorage(), new NullLogger(), 'test');
        $kernel = $this->createStub(HttpKernelInterface::class);
        foreach (['198.51.100.20', '198.51.100.21'] as $index => $spoofed) {
            $request = Request::create('http://api.baander.app/api/auth/me', server: [
                'REMOTE_ADDR' => '172.18.0.10',
                'HTTP_X_FORWARDED_FOR' => $spoofed,
            ]);
            if ($index === 1) {
                $this->expectException(TooManyRequestsHttpException::class);
            }
            $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        }
    }

    public function testConfiguredProxyClientsHaveSeparateApiBudgets(): void
    {
        (new SetRequestRuntimeConfiguration())->boot(['trustedProxies' => ['192.0.2.10']]);
        $factory = new RateLimiterFactory(['id' => 'anonymous_api', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'], new InMemoryStorage());
        $listener = new ApiRateLimitListener($factory, $factory, new TokenStorage(), new NullLogger(), 'test');
        $kernel = $this->createStub(HttpKernelInterface::class);
        foreach (['198.51.100.20', '198.51.100.21'] as $client) {
            $request = Request::create('http://api.baander.app/api/auth/me', server: [
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_X_FORWARDED_FOR' => $client,
            ]);
            $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            self::assertSame($client, $request->getClientIp());
        }
    }

    /** @return iterable<string, array{string, ?string, string, string}> */
    public static function swoolePeers(): iterable
    {
        // An IPv4 connection to a dual-stack socket reaches Swoole as an IPv4-mapped IPv6 address.
        yield 'gateway over IPv4' => ['::ffff:10.231.0.2', '198.51.100.31', '10.231.0.2', '198.51.100.31'];
        yield 'gateway over IPv4, hexadecimal spelling' => ['::ffff:ae7:2', '198.51.100.32', '10.231.0.2', '198.51.100.32'];
        yield 'gateway over IPv6' => ['fd00:baa:231::2', '198.51.100.33', 'fd00:baa:231::2', '198.51.100.33'];
        yield 'gateway on an IPv4 socket' => ['10.231.0.2', '198.51.100.34', '10.231.0.2', '198.51.100.34'];
        yield 'direct IPv4 client forging its address' => ['::ffff:198.51.100.35', '203.0.113.9', '198.51.100.35', '198.51.100.35'];
        yield 'direct IPv6 client' => ['2001:db8::35', null, '2001:db8::35', '2001:db8::35'];
    }

    #[DataProvider('swoolePeers')]
    public function testSwooleRequestsCarryOneAddressPerPeer(string $peer, ?string $forwarded, string $remoteAddress, string $client): void
    {
        (new SetRequestRuntimeConfiguration())->boot(['trustedProxies' => ['10.231.0.2', 'fd00:baa:231::2']]);

        $request = new DefaultRequestFactory()->make(self::swooleRequest($peer, $forwarded));

        self::assertSame($remoteAddress, $request->server->get('REMOTE_ADDR'));
        self::assertSame($client, $request->getClientIp());
    }

    public function testAPeerHasOneApiBudgetWhicheverSocketFamilyItArrivesThrough(): void
    {
        (new SetRequestRuntimeConfiguration())->boot(['trustedProxies' => []]);
        $factory = new RateLimiterFactory(['id' => 'anonymous_api', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'], new InMemoryStorage());
        $listener = new ApiRateLimitListener($factory, $factory, new TokenStorage(), new NullLogger(), 'test');
        $kernel = $this->createStub(HttpKernelInterface::class);
        foreach (['198.51.100.41', '::ffff:198.51.100.41'] as $index => $peer) {
            $request = new DefaultRequestFactory()->make(self::swooleRequest($peer, null));
            if ($index === 1) {
                $this->expectException(TooManyRequestsHttpException::class);
            }
            $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        }
    }

    public function testTrustingEveryProxyTrustsAMappedPeerInItsIpv4Form(): void
    {
        $handler = new TrustAllProxiesRequestHandler(new class implements RequestHandler {
            public function handle(SwooleRequest $request, SwooleResponse $response): void {}
        }, trustAllProxies: true);
        $peer = self::swooleRequest('::ffff:10.231.0.7', '198.51.100.45');

        $handler->handle($peer, new SwooleResponse());

        self::assertSame('198.51.100.45', new DefaultRequestFactory()->make($peer)->getClientIp());
    }

    private static function swooleRequest(string $peer, ?string $forwarded): SwooleRequest
    {
        $request = new SwooleRequest();
        $request->server = ['request_method' => 'GET', 'request_uri' => '/api/auth/me', 'server_protocol' => 'HTTP/1.1', 'remote_addr' => $peer, 'remote_port' => 41418];
        $request->header = ['host' => 'api.baander.app'] + ($forwarded === null ? [] : ['x-forwarded-for' => $forwarded]);

        return $request;
    }

    /** @return iterable<string, array{bool, string, ?string, string}> */
    public static function trustedUrlCases(): iterable
    {
        foreach ([false, true] as $trusted) {
            $peer = $trusted ? 'trusted proxy' : 'untrusted peer';
            yield $peer . ' with explicit forwarded port' => [$trusted, 'api.baander.app', '443', 'https://api.baander.app'];
            yield $peer . ' with default HTTPS authority' => [$trusted, 'api.baander.app', null, 'https://api.baander.app'];
            yield $peer . ' with custom HTTPS authority' => [$trusted, 'api.baander.app:18443', null, 'https://api.baander.app:18443'];
        }
    }
}
