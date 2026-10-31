<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\BlockingContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Modifier\Builder\Symfony63PlusBuilder;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Modifier\Modifier;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use ZEngine\Reflection\ReflectionClass;

final class ContainerInitializationBuilderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        self::assertTrue(extension_loaded('ffi'), 'FFI is required; this proof must not skip.');
        ClassModifier::initialize();
        BlockingContainer::initializeMutex(new Swoole());
        $this->directory = sys_get_temp_dir() . '/baander-container-builder-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testEmittedSymfonyLazyFactoryHandlesFlagsServiceIdAndNativeGhost(): void
    {
        [$container, $reflection, $factory] = $this->dumpContainer();
        $builder = new Symfony63PlusBuilder();
        $builder->overrideGeneratedContainer($reflection, $this->directory, false);
        $builder->overrideGeneratedContainerGetters($reflection, $this->directory);
        $factoryClass = $reflection->getNamespaceName() . '\\' . basename($factory, '.php');

        $ghost = $factoryClass::do($container, true);
        self::assertTrue((new \ReflectionClass($ghost))->isUninitializedLazyObject($ghost));
        self::assertSame($ghost, $factoryClass::do($container, 'probe'));
        self::assertSame('constructed', $ghost->value());
        self::assertSame(1, $ghost->constructions);
        self::assertSame($ghost, $factoryClass::do($container, false));
        self::assertSame(1, $ghost->constructions);
    }

    public function testRepairsOrphanParentsAndIgnoresTemporaryFilesUsingReflectedDirectory(): void
    {
        [$container, $reflection, $factory] = $this->dumpContainer();
        $filesystem = new Filesystem();
        $containerSource = file_get_contents($reflection->getFileName());
        $factorySource = file_get_contents($factory);
        self::assertIsString($containerSource);
        self::assertIsString($factorySource);
        $temporary = dirname($factory) . '/getIncomplete.php.tmp123';
        $filesystem->dumpFile($temporary, 'an incomplete Filesystem::dumpFile temporary');
        $builder = new Symfony63PlusBuilder();
        $builder->overrideGeneratedContainer($reflection, $this->directory . '/stale-alias', false);
        $builder->overrideGeneratedContainerGetters($reflection, $this->directory . '/stale-alias');

        $filesystem->dumpFile($reflection->getFileName(), $containerSource);
        $filesystem->dumpFile($factory, $factorySource);
        $builder->overrideGeneratedContainer($reflection, $this->directory . '/stale-alias', false);
        $builder->overrideGeneratedContainerGetters($reflection, $this->directory . '/stale-alias');

        self::assertStringContainsString('extends ' . $reflection->getShortName() . '_Overridden', file_get_contents($reflection->getFileName()));
        self::assertStringContainsString('extends ' . basename($factory, '.php') . '__Overridden', file_get_contents($factory));
        self::assertSame('an incomplete Filesystem::dumpFile temporary', file_get_contents($temporary));

        $filesystem->remove($factory);
        $builder->overrideGeneratedContainerGetters($reflection, $this->directory . '/stale-alias');
        self::assertStringContainsString('extends ' . basename($factory, '.php') . '__Overridden', file_get_contents($factory));
    }

    public function testRejectsWrapperWhoseParentHasDisappeared(): void
    {
        [$container, $reflection, $factory] = $this->dumpContainer();
        $builder = new Symfony63PlusBuilder();
        $builder->overrideGeneratedContainerGetters($reflection, $this->directory);
        (new Filesystem())->remove(str_replace('.php', '__Overridden.php', $factory));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parent');
        $builder->overrideGeneratedContainerGetters($reflection, $this->directory);
    }

    public function testModifierRechecksAContainerRedumpedWithTheSameClassName(): void
    {
        [$container, $reflection, $factory] = $this->dumpContainer();
        $filesystem = new Filesystem();
        $containerSource = file_get_contents($reflection->getFileName());
        $factorySource = file_get_contents($factory);
        self::assertIsString($containerSource);
        self::assertIsString($factorySource);
        Modifier::modifyContainer($container, $this->directory, false);
        $filesystem->dumpFile($reflection->getFileName(), $containerSource);
        $filesystem->dumpFile($factory, $factorySource);

        Modifier::modifyContainer($container, $this->directory, false);

        self::assertStringContainsString('extends ' . $reflection->getShortName() . '_Overridden', file_get_contents($reflection->getFileName()));
        self::assertStringContainsString('extends ' . basename($factory, '.php') . '__Overridden', file_get_contents($factory));
    }

    public function testInitializedServiceCacheDoesNotRetainADiscardedContainer(): void
    {
        [$container, $reflection, $factory] = $this->dumpContainer();
        $builder = new Symfony63PlusBuilder();
        $builder->overrideGeneratedContainerGetters($reflection, $this->directory);
        $factoryClass = $reflection->getNamespaceName() . '\\' . basename($factory, '.php');
        $service = $factoryClass::do($container, true);
        self::assertSame('constructed', $service->value());
        self::assertSame($container, $service->container);
        self::assertSame($service, $factoryClass::do($container, false));
        $reference = \WeakReference::create($container);

        unset($service, $container);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    /** @return array{BlockingContainer, ReflectionClass, string} */
    private function dumpContainer(): array
    {
        $builder = new ContainerBuilder();
        $builder->register('probe', ContainerInitializationBuilderService::class)
            ->addArgument(new Reference('service_container'))
            ->setPublic(true)
            ->setLazy(true);
        $builder->compile();
        $class = 'BuilderProof' . bin2hex(random_bytes(8));
        $files = (new PhpDumper($builder))->dump(['class' => $class, 'base_class' => BlockingContainer::class, 'as_files' => true]);
        self::assertIsArray($files);
        $filesystem = new Filesystem();
        $factory = null;
        foreach ($files as $file => $source) {
            $filesystem->dumpFile($this->directory . '/' . $file, $source);
            if (str_starts_with(basename($file), 'getProbe')) {
                $factory = $this->directory . '/' . $file;
            }
        }
        self::assertIsString($factory);
        $container = require $this->directory . '/' . $class . '.php';
        self::assertInstanceOf(BlockingContainer::class, $container);

        return [$container, new ReflectionClass($container), $factory];
    }
}

class ContainerInitializationBuilderService
{
    public int $constructions = 0;
    private string $state;

    public function __construct(public readonly object $container)
    {
        ++$this->constructions;
        $this->state = 'constructed';
    }

    public function value(): string
    {
        return $this->state;
    }
}
