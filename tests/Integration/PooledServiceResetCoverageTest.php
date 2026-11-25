<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\DiServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\SimpleResetter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Compares, for every pooled service of the production container, the kernel.reset methods
 * Symfony collected for it with the methods its pool's resetter runs when a coroutine releases
 * the instance. A resetter service named on the stateful tag replaces those methods on purpose
 * and is not compared.
 */
final class PooledServiceResetCoverageTest extends TestCase
{
    private static PooledServiceResetCoverageKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        $directory = sys_get_temp_dir() . '/baander-reset-coverage-' . bin2hex(random_bytes(8));
        self::$kernel = new PooledServiceResetCoverageKernel($directory);
        try {
            self::$kernel->boot();
        } finally {
            self::$kernel->shutdown();
            new Filesystem()->remove($directory);
        }
    }

    public function testPoolThatRunsFewerMethodsThanItsServiceDeclaresIsReported(): void
    {
        self::assertSame(
            ['forgetSecond'],
            self::droppedResetMethods()[PooledServiceResetCoverageKernel::TRUNCATED_SERVICE] ?? [],
        );
    }

    public function testEveryPoolOfTheProductionContainerRunsAllKernelResetMethodsOfItsService(): void
    {
        $dropped = self::droppedResetMethods();
        unset($dropped[PooledServiceResetCoverageKernel::TRUNCATED_SERVICE]);

        self::assertSame([], $dropped, 'kernel.reset methods that no pool resetter runs, by service id');
    }

    public function testTokenStoragesAndAuthorizationCheckerArePooledWithTheirResetMethods(): void
    {
        $pools = self::$kernel->pools;

        self::assertSame(
            [
                'security.token_storage' => ['disableUsageTracking', 'setToken'],
                'security.untracked_token_storage' => ['reset'],
                'security.authorization_checker' => [],
            ],
            [
                'security.token_storage' => $pools['security.token_storage']['methods'] ?? null,
                'security.untracked_token_storage' => $pools['security.untracked_token_storage']['methods'] ?? null,
                'security.authorization_checker' => $pools['security.authorization_checker']['methods'] ?? null,
            ],
        );
    }

    public function testTheLogProcessorsThatHoldRequestStateArePooled(): void
    {
        // Each coroutine logs its own request's URL and correlation ID.
        foreach ([\App\Shared\Infrastructure\Logging\BoundedWebProcessor::class, \App\Shared\Infrastructure\Logging\CorrelationIdProcessor::class] as $processor) {
            self::assertSame(['reset'], self::$kernel->pools[$processor]['methods'] ?? null, $processor);
        }
    }

    /**
     * @return array<string, list<string>> by service id, the kernel.reset methods its pool's resetter does not run
     */
    private static function droppedResetMethods(): array
    {
        $dropped = [];
        foreach (self::$kernel->pools as $serviceId => $pool) {
            if ($pool['resetter'] !== null) {
                continue;
            }

            $missing = array_diff(self::declaredResetMethods($serviceId, $pool['class']), $pool['methods']);
            if ($missing !== []) {
                $dropped[$serviceId] = array_values($missing);
            }
        }

        return $dropped;
    }

    /**
     * An optional method (a leading ?) counts only when the service class declares it, the rule
     * ServicesResetter applies to the instance.
     *
     * @return list<string>
     */
    private static function declaredResetMethods(string $serviceId, string $class): array
    {
        $declared = [];
        foreach (self::$kernel->declared[$serviceId] ?? [] as $method) {
            $name = ltrim($method, '?');
            if ($name === $method || method_exists($class, $name)) {
                $declared[] = $name;
            }
        }

        return $declared;
    }
}

final class PooledServiceResetCoverageKernel extends Kernel
{
    public const string TRUNCATED_SERVICE = 'test.pooled_reset_coverage.truncated';

    private const string POOL_SUFFIX = '.swoole_coop.service_pool';

    /** @var array<string, list<string>> by service id, the kernel.reset methods ResettableServicePass collected; a leading ? marks an optional one */
    public array $declared = [];

    /** @var array<string, array{class: string, methods: list<string>, resetter: ?string}> by pooled service id */
    public array $pools = [];

    public function __construct(private readonly string $directory)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->directory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->register(self::TRUNCATED_SERVICE, PooledServiceResetCoverageFixture::class)
            ->addTag('kernel.reset', ['method' => 'forgetFirst'])
            ->addTag('kernel.reset', ['method' => 'forgetSecond']);
        // StatefulServicesPass runs at TYPE_BEFORE_REMOVING, -10000: it builds the pools and then
        // cuts the method map of services_resetter down to the services it does not pool. The pools
        // are read right after it, before the removing passes drop or inline unused definitions.
        $container->addCompilerPass(new class ($this) implements CompilerPassInterface {
            public function __construct(private readonly PooledServiceResetCoverageKernel $kernel) {}

            public function process(ContainerBuilder $container): void
            {
                $this->kernel->declared = $container->findDefinition('services_resetter')->getArgument(1);
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, -9999);
        $container->addCompilerPass(new class ($this) implements CompilerPassInterface {
            public function __construct(private readonly PooledServiceResetCoverageKernel $kernel) {}

            public function process(ContainerBuilder $container): void
            {
                $this->kernel->recordPools($container);
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, -10001);
    }

    public function recordPools(ContainerBuilder $container): void
    {
        // The fixture's pool is left with its first method only, as a pass that keeps one method per service would leave it.
        $truncated = $container->getDefinition(self::TRUNCATED_SERVICE . self::POOL_SUFFIX)->getArgument(5);
        $truncated->setArgument(0, array_slice($truncated->getArgument(0), 0, 1));

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->getClass() !== DiServicePool::class || !str_ends_with($id, self::POOL_SUFFIX)) {
                continue;
            }

            $resetter = $definition->getArgument(5);
            $this->pools[substr($id, 0, -strlen(self::POOL_SUFFIX))] = [
                'class' => (string) $container->getDefinition((string) $definition->getArgument(0))->getClass(),
                'methods' => $resetter instanceof Definition && $resetter->getClass() === SimpleResetter::class
                    ? $resetter->getArgument(0)
                    : [],
                'resetter' => $resetter instanceof Reference ? (string) $resetter : null,
            ];
        }
    }
}

class PooledServiceResetCoverageFixture
{
    public function forgetFirst(): void {}

    public function forgetSecond(): void {}
}
