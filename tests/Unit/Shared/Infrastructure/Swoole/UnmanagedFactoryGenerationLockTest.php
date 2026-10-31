<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use Laminas\Code\Generator\ClassGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProxyManager\Configuration;
use ProxyManager\Factory\AccessInterceptorValueHolderFactory;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use ProxyManager\GeneratorStrategy\GeneratorStrategyInterface;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Generator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Instantiator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\UnmanagedFactoryInstantiator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use SwooleBundle\SwooleBundle\Component\Locking\MutexFactory;

final class UnmanagedFactoryGenerationLockTest extends TestCase
{
    public function testFactoryAndReturnedServiceGenerationUseOneWorkerLifetimeLock(): void
    {
        [$instantiator, $mutex, $strategy] = $this->instantiator();
        $factory = $instantiator->newInstance(new UnmanagedFactoryGenerationFixture(), [
            ['factoryMethod' => 'create', 'returnType' => UnmanagedFactoryGenerationProduct::class],
        ], 5);
        $first = $factory->create();
        $second = $factory->create();

        self::assertSame([true, true], $strategy->generationLockStates);
        self::assertSame('ready', $first->value());
        self::assertSame('ready', $second->value());
        self::assertSame(3, $mutex->acquisitions);
        self::assertSame(3, $mutex->releases);
        self::assertFalse($mutex->isAcquired());
    }

    public function testConcurrentServiceLocatorStyleCallsGenerateEachProxyOnce(): void
    {
        self::assertTrue(extension_loaded('swoole'), 'Swoole is required; this proof must not skip.');
        $script = <<<'PHP'
require $argv[1];
require $argv[2];
$adapter = new SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole();
$locking = new class($adapter) implements SwooleBundle\SwooleBundle\Component\Locking\MutexFactory {
    public function __construct(private readonly SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole $swoole) {}
    public function newMutex(): SwooleBundle\SwooleBundle\Component\Locking\Mutex {
        return new SwooleBundle\SwooleBundle\Component\Locking\RecursiveOwner\RecursiveOwnerMutex(
            $this->swoole,
            new SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutex(acquireTimeout: .25),
        );
    }
};
$mutex = $locking->newMutex();
$instantiation = new class($mutex) implements SwooleBundle\SwooleBundle\Component\Locking\MutexFactory {
    public function __construct(private readonly SwooleBundle\SwooleBundle\Component\Locking\Mutex $mutex) {}
    public function newMutex(): SwooleBundle\SwooleBundle\Component\Locking\Mutex { return $this->mutex; }
};
$strategy = new App\Tests\Unit\Shared\Infrastructure\Swoole\UnmanagedFactoryGenerationStrategy($mutex);
$strategy->yieldDuringGeneration = true;
$configuration = new ProxyManager\Configuration();
$configuration->setGeneratorStrategy($strategy);
$instantiator = new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\UnmanagedFactoryInstantiator(
    new ProxyManager\Factory\AccessInterceptorValueHolderFactory($configuration),
    new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Instantiator(
        new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Generator($configuration),
    ),
    new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer([]),
    $locking, $locking, $adapter, $instantiation,
);
$results = [];
$errors = [];
Swoole\Coroutine\run(static function () use ($instantiator, &$results, &$errors): void {
    for ($index = 0; $index < 2; ++$index) {
        Swoole\Coroutine::create(static function () use ($instantiator, &$results, &$errors): void {
            try {
                $factory = $instantiator->newInstance(
                    new App\Tests\Unit\Shared\Infrastructure\Swoole\UnmanagedFactoryGenerationFixture(),
                    [['factoryMethod' => 'create', 'returnType' => App\Tests\Unit\Shared\Infrastructure\Swoole\UnmanagedFactoryGenerationProduct::class]],
                    5,
                );
                $results[] = $factory->create()->value();
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        });
    }
});
echo json_encode([$results, $errors, $strategy->generationLockStates, $mutex->isAcquired()], JSON_THROW_ON_ERROR);
PHP;
        $process = CoroutineProbe::create(['-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php', __FILE__]);
        $process->setTimeout(5);
        self::assertSame(0, $process->run(), $process->getErrorOutput() . $process->getOutput());
        self::assertSame([['ready', 'ready'], [], [true, true], false], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{int}> */
    public static function failures(): iterable
    {
        yield 'factory generation' => [1];
        yield 'returned service generation' => [2];
    }

    #[DataProvider('failures')]
    public function testGenerationFailureReleasesTheInstantiationLock(int $failureAt): void
    {
        [$instantiator, $mutex, $strategy] = $this->instantiator();
        $strategy->failureAt = $failureAt;

        try {
            $factory = $instantiator->newInstance(new UnmanagedFactoryGenerationFixture(), [
                ['factoryMethod' => 'create', 'returnType' => UnmanagedFactoryGenerationProduct::class],
            ], 5);
            self::fail('The generator must throw instead of returning ' . $factory->create()->value());
        } catch (\LogicException $exception) {
            self::assertSame('generation failed', $exception->getMessage());
        }

        self::assertSame(array_fill(0, $failureAt, true), $strategy->generationLockStates);
        self::assertSame($failureAt, $mutex->acquisitions);
        self::assertSame($mutex->acquisitions, $mutex->releases);
        self::assertFalse($mutex->isAcquired());
    }

    /** @return array{UnmanagedFactoryInstantiator, UnmanagedFactoryGenerationMutex, UnmanagedFactoryGenerationStrategy} */
    private function instantiator(): array
    {
        $mutex = new UnmanagedFactoryGenerationMutex();
        $strategy = new UnmanagedFactoryGenerationStrategy($mutex);
        $configuration = new Configuration();
        $configuration->setProxiesNamespace('UnmanagedFactoryGeneration' . bin2hex(random_bytes(8)));
        $configuration->setGeneratorStrategy($strategy);
        $factory = new class ($mutex) implements MutexFactory {
            public function __construct(private readonly Mutex $mutex) {}

            public function newMutex(): Mutex
            {
                return $this->mutex;
            }
        };
        $otherLocks = new class implements MutexFactory {
            public function newMutex(): Mutex
            {
                return new UnmanagedFactoryGenerationMutex();
            }
        };
        $instantiator = new UnmanagedFactoryInstantiator(
            new AccessInterceptorValueHolderFactory($configuration),
            new Instantiator(new Generator($configuration)),
            new ServicePoolContainer([]),
            $otherLocks,
            $otherLocks,
            new Swoole(),
            $factory,
        );

        return [$instantiator, $mutex, $strategy];
    }
}

final class UnmanagedFactoryGenerationStrategy implements GeneratorStrategyInterface
{
    /** @var list<bool> */
    public array $generationLockStates = [];
    public ?int $failureAt = null;
    public bool $yieldDuringGeneration = false;

    public function __construct(private readonly Mutex $mutex) {}

    public function generate(ClassGenerator $classGenerator): string
    {
        $this->generationLockStates[] = $this->mutex->isAcquired();
        if (count($this->generationLockStates) === $this->failureAt) {
            throw new \LogicException('generation failed');
        }

        if ($this->yieldDuringGeneration) {
            \Swoole\Coroutine::sleep(.01);
        }

        return (new EvaluatingGeneratorStrategy())->generate($classGenerator);
    }
}

final class UnmanagedFactoryGenerationMutex implements Mutex
{
    public int $acquisitions = 0;
    public int $releases = 0;

    public function acquire(): void
    {
        ++$this->acquisitions;
    }

    public function release(): void
    {
        ++$this->releases;
    }

    public function isAcquired(): bool
    {
        return $this->acquisitions > $this->releases;
    }
}

class UnmanagedFactoryGenerationFixture
{
    public function create(): UnmanagedFactoryGenerationProduct
    {
        return new UnmanagedFactoryGenerationProduct();
    }
}

class UnmanagedFactoryGenerationProduct
{
    public function value(): string
    {
        return 'ready';
    }
}
