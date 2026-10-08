<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;

/**
 * The Swoole management API has no authentication, so a bundle configuration
 * that does not name a host must bind it to loopback.
 */
final class SwooleApiHostConfigurationTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function apiConfigurationsWithoutHost(): iterable
    {
        yield 'api omitted' => [null];
        yield 'api: true shorthand' => [true];
        yield 'api array without host' => [['enabled' => true, 'port' => 9201]];
    }

    #[DataProvider('apiConfigurationsWithoutHost')]
    public function testApiHostDefaultsToLoopback(mixed $api): void
    {
        $httpServer = $api === null ? [] : ['api' => $api];

        self::assertSame('127.0.0.1', $this->process($httpServer)['api']['host']);
    }

    /**
     * @param array<string, mixed> $httpServer
     *
     * @return array{api: array{enabled: bool, host: string, port: int}}
     */
    private function process(array $httpServer): array
    {
        /** @var array{http_server: array{api: array{enabled: bool, host: string, port: int}}} $config */
        $config = (new Processor())->processConfiguration(
            Configuration::fromTreeBuilder(),
            [['http_server' => $httpServer]],
        );

        return $config['http_server'];
    }
}
