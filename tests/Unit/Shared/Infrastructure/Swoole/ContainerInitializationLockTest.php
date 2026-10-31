<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Kernel\CoroutinesSupportingKernel;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ContainerInitializationLockTest extends TestCase
{
    public function testLockOpenFailurePreventsParentInitialization(): void
    {
        $directory = sys_get_temp_dir() . '/baander-container-lock-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);
        $filesystem->mkdir($directory . '/FixtureContainer.php.swoole.lock');
        $kernel = new ContainerInitializationLockKernel($directory);

        try {
            try {
                $kernel->bootForTest();
                self::fail('A container must not initialize without its exclusive lock.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('lock', $exception->getMessage());
                self::assertFalse($kernel->parentInitialized);
            }
        } finally {
            $filesystem->remove($directory);
        }
    }

    public function testColdAndWarmInitializationsAreExclusiveAcrossProcesses(): void
    {
        $directory = sys_get_temp_dir() . '/baander-container-lock-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $autoload = dirname(__DIR__, 5) . '/vendor/autoload.php';
        $script = 'require $argv[1]; require $argv[2]; '
            . '(new \\' . ContainerInitializationLockKernel::class . '($argv[3]))->bootForTest();';

        try {
            foreach ([0, 1] as $round) {
                $processes = [];
                for ($index = 0; $index < 4; ++$index) {
                    $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $script, $autoload, __FILE__, $directory]);
                    $process->start();
                    $processes[] = $process;
                }
                foreach ($processes as $process) {
                    self::assertSame(0, $process->wait(), $process->getErrorOutput() . $process->getOutput());
                }
            }
            self::assertFileDoesNotExist($directory . '/overlap');
            self::assertFileExists($directory . '/FixtureContainer.php.swoole.lock');
        } finally {
            $filesystem->remove($directory);
        }
    }
}

class ContainerInitializationLockParent
{
    protected Container $container;
    public bool $parentInitialized = false;

    public function __construct(private readonly string $directory) {}

    public function getBuildDir(): string
    {
        return $this->directory;
    }

    public function getCacheDir(): string
    {
        return $this->directory;
    }

    protected function getContainerClass(): string
    {
        return 'FixtureContainer';
    }

    protected function initializeContainer(): void
    {
        $this->parentInitialized = true;
        (new Filesystem())->mkdir($this->directory);
        $guard = fopen($this->directory . '/parent.guard', 'c+');
        if ($guard === false) {
            throw new \RuntimeException('Cannot open fixture guard.');
        }
        if (!flock($guard, LOCK_EX | LOCK_NB)) {
            file_put_contents($this->directory . '/overlap', 'overlap');
        }
        usleep(100000);
        flock($guard, LOCK_UN);
        fclose($guard);
        $this->container = new Container();
    }
}

final class ContainerInitializationLockKernel extends ContainerInitializationLockParent
{
    use CoroutinesSupportingKernel;

    public function bootForTest(): void
    {
        $this->initializeContainer();
    }
}
