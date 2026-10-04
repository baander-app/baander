<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Modifier\Builder\Symfony63PlusBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class GeneratedContainerLoadTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function serviceNames(): iterable
    {
        yield 'filename with extension' => ['getService.php', true];
        yield 'filename without extension' => ['getService', false];
    }

    #[DataProvider('serviceNames')]
    public function testGeneratedLoaderUsesConfiguredContainerDirectory(string $file, bool $lazyLoad): void
    {
        $namespace = 'GeneratedContainerLoad' . bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/' . $namespace;
        $serviceDirectory = $directory . '/services';
        $sourceDirectory = $directory . '/source';
        $workingDirectory = $directory . '/working';
        $filesystem = new Filesystem();
        $filesystem->mkdir([$serviceDirectory, $sourceDirectory, $workingDirectory]);
        $originalDirectory = getcwd();
        self::assertIsString($originalDirectory);

        try {
            $method = new \ReflectionMethod(Symfony63PlusBuilder::class, 'generateOverridenGeneratedLoad');
            $generatedLoad = $method->invoke(null);
            self::assertIsString($generatedLoad);
            $fixture = '<?php namespace ' . $namespace . '; class Container extends \\' . GeneratedContainerLoadFixture::class . ' {'
                . $generatedLoad . '} return new Container($serviceDirectory);';
            $filesystem->dumpFile($sourceDirectory . '/Container.php', $fixture);
            $filesystem->dumpFile($serviceDirectory . '/getService__Overridden.php', '<?php namespace ' . $namespace . '; class getService__Overridden {}');
            $filesystem->dumpFile($serviceDirectory . '/getService.php', '<?php namespace ' . $namespace . '; class getService extends getService__Overridden {
                public static function do(bool $lazyLoad): array { return ["loaded" => true, "lazy" => $lazyLoad]; }
            }');
            $container = require $sourceDirectory . '/Container.php';
            self::assertInstanceOf(GeneratedContainerLoadFixture::class, $container);
            $mutex = new class {
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
            };
            GeneratedContainerLoadFixture::$mutex = $mutex;
            chdir($workingDirectory);

            self::assertSame(['loaded' => true, 'lazy' => $lazyLoad], $container->loadForTest($file, $lazyLoad));
            self::assertSame(1, $mutex->acquisitions);
            self::assertSame(1, $mutex->releases);
        } finally {
            chdir($originalDirectory);
            $filesystem->remove($directory);
        }
    }
}

abstract class GeneratedContainerLoadFixture
{
    public static object $mutex;
    protected static string $buildContainerNs;

    public function __construct(protected string $containerDir)
    {
        self::$buildContainerNs = (new \ReflectionClass($this))->getNamespaceName();
    }

    public function loadForTest(string $file, bool $lazyLoad): mixed
    {
        return $this->load($file, $lazyLoad);
    }

    protected function load(string $file, bool $lazyLoad = true): mixed
    {
        $class = self::$buildContainerNs . '\\' . basename($file, '.php');
        $filename = str_ends_with($file, '.php') ? $file : $file . '.php';
        require_once $this->containerDir . DIRECTORY_SEPARATOR . $filename;

        return $class::do($lazyLoad);
    }
}
