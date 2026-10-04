<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Kernel;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class CacheInitializationTest extends TestCase
{
    public function testInvalidCacheDirectoryFailsBeforeContainerCompilation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-cache-');
        self::assertIsString($path);
        try {
            $this->expectException(IOException::class);
            $this->kernel($path)->initializeForTest();
        } finally {
            unlink($path);
        }
    }

    public function testUnavailableLockFailsInsteadOfCompilingWithoutExclusion(): void
    {
        $directory = sys_get_temp_dir() . '/baander-cache-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory . '/.container.lock');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot open the container initialization lock.');
            $this->kernel($directory)->initializeForTest();
        } finally {
            $filesystem->remove($directory);
        }
    }

    private function kernel(string $directory): CacheInitializationKernel
    {
        return new CacheInitializationKernel($directory);
    }
}

final class CacheInitializationKernel extends Kernel
{
    public function __construct(private readonly string $cacheDirectory)
    {
        parent::__construct('test', false);
    }

    public function getCacheDir(): string
    {
        return $this->cacheDirectory;
    }

    public function initializeForTest(): void
    {
        $this->initializeContainer();
    }
}
