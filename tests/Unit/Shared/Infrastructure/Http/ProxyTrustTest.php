<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Auth\Application\Port\DpopJtiCacheInterface;
use App\Auth\Infrastructure\Security\ApiRateLimitListener;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use Assert\Assertion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command\ServerStartCommand;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\Configuration;
use SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation\SetRequestRuntimeConfiguration;
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
    public function testSignedProofUsesUrlFromTrustedPeerOnly(bool $trusted, string $forwardedHost, ?string $forwardedPort, string $externalOrigin): void
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
        $proof = new SignedDpopProof();
        $cache = $this->createMock(DpopJtiCacheInterface::class);
        $cache->expects($trusted ? $this->once() : $this->never())->method('isReplay')->willReturn(false);
        $result = (new DpopProofValidator($cache))->validate(
            $proof->create('GET', $externalOrigin . '/api/auth/me', 'disposable-access-token'),
            $request,
            'disposable-access-token',
            $proof->thumbprint(),
        );
        self::assertSame($trusted, $result->isValid());
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
