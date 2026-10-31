<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use LogicException;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\ClassModificationProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Cache\CacheAdapterProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\SimpleResetter;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;

final class CacheAdapterProcessorTest extends TestCase
{
    public function testTraceableResetClearsCallsWithoutClearingSharedCachedValues(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->register('cache.traced', TraceableAdapter::class);
        $this->process($container);
        $resetter = $this->resetter($container, $definition);
        $shared = new ArrayAdapter();
        $item = $shared->getItem('cached');
        $item->set('retained');
        $shared->save($item);
        $traceable = new TraceableAdapter($shared);
        $traceable->getItem('cached');
        self::assertNotEmpty($traceable->getCalls());

        $resetter->reset($traceable);

        self::assertSame([], $traceable->getCalls());
        self::assertSame('retained', $shared->getItem('cached')->get());
    }

    public function testChainResetCommitsDeferredItemsWithoutClearingEntries(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->register('cache.chain', ChainAdapter::class);
        $this->process($container);
        $directory = sys_get_temp_dir() . '/baander-cache-' . bin2hex(random_bytes(6));
        $shared = new FilesystemAdapter('baander', 0, $directory);
        try {
            $chain = new ChainAdapter([$shared]);
            $item = $chain->getItem('deferred');
            $item->set('committed');
            $chain->saveDeferred($item);

            $this->resetter($container, $definition)->reset($chain);

            self::assertSame('committed', $shared->getItem('deferred')->get());
        } finally {
            new Filesystem()->remove($directory);
        }
    }

    public function testSystemFactoryRetainsFactoryAndNamesItsActualConcreteClass(): void
    {
        $container = new ContainerBuilder();
        $factory = [AbstractAdapter::class, 'createSystemCache'];
        $definition = $container->register('cache.system', AdapterInterface::class)->setFactory($factory);

        $this->process($container);

        $expected = ApcuAdapter::isSupported() && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL))
            ? ChainAdapter::class : PhpFilesAdapter::class;
        self::assertSame($expected, $definition->getClass());
        self::assertSame($factory, $definition->getFactory());
        self::assertSame('reset', $this->resetMethod($container, $definition));
    }

    public function testIncompleteOptionalAndAbstractDefinitionsAreNotTagged(): void
    {
        $container = new ContainerBuilder();
        $container->register('cache.abstract', ChainAdapter::class)->setAbstract(true);
        $container->register('cache.incomplete', PhpFilesAdapter::class)->setArgument(0, new AbstractArgument());
        $container->register('cache.optional', 'App\\OptionalCacheAdapter');
        $container->register('cache.interface', AdapterInterface::class);
        $container->register('cache.no_class');
        $container->register('cache.array', ArrayAdapter::class);

        $this->process($container);

        foreach ($container->getDefinitions() as $definition) {
            self::assertFalse($definition->hasTag(ContainerConstants::TAG_STATEFUL_SERVICE));
        }
    }

    public function testThrowingOptionalAutoloaderDoesNotPreventKnownCacheProcessing(): void
    {
        $optionalClass = 'App\\Tests\\Fixtures\\OptionalFormCacheAdapter';
        $attempted = false;
        $autoload = static function (string $class) use ($optionalClass, &$attempted): void {
            if ($class === $optionalClass) {
                $attempted = true;
                throw new LogicException('The optional form dependency is unavailable.');
            }
        };
        spl_autoload_register($autoload, prepend: true);
        try {
            $container = new ContainerBuilder();
            $optional = $container->register('cache.optional_form', $optionalClass);
            $valid = $container->register('cache.valid', TraceableAdapter::class);

            $this->process($container);

            self::assertTrue($attempted);
            self::assertFalse($optional->hasTag(ContainerConstants::TAG_STATEFUL_SERVICE));
            self::assertSame('clearCalls', $this->resetMethod($container, $valid));
        } finally {
            spl_autoload_unregister($autoload);
        }
    }

    public function testKnownCacheTaggingFailuresAreNotSuppressed(): void
    {
        $container = new ContainerBuilder();
        $definition = $this->createMock(Definition::class);
        $definition->method('getClass')->willReturn(TraceableAdapter::class);
        $definition->method('getArguments')->willReturn([]);
        $definition->expects(self::once())->method('addTag')->willThrowException(new LogicException('Cache tagging failed.'));
        $container->setDefinition('cache.valid', $definition);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cache tagging failed.');

        $this->process($container);
    }

    private function process(ContainerBuilder $container): void
    {
        new CacheAdapterProcessor()->process($container, new Proxifier($container, new ClassModificationProcessor($container)));
    }

    private function resetter(ContainerBuilder $container, Definition $definition): SimpleResetter
    {
        return new SimpleResetter($this->resetMethod($container, $definition));
    }

    private function resetMethod(ContainerBuilder $container, Definition $definition): string
    {
        $tags = $definition->getTag(ContainerConstants::TAG_STATEFUL_SERVICE);
        self::assertCount(1, $tags);

        return $container->getDefinition($tags[0]['resetter'])->getArgument(0);
    }
}
