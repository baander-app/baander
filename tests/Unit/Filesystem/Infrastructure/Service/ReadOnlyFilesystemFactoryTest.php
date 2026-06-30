<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Infrastructure\Service;

use App\Filesystem\Application\Port\ReadOnlyFilesystemPortInterface;
use App\Filesystem\Domain\ValueObject\FilesystemType;
use App\Filesystem\Infrastructure\Service\LocalFilesystem;
use App\Filesystem\Infrastructure\Service\ReadOnlyFilesystem;
use App\Filesystem\Infrastructure\Service\ReadOnlyFilesystemFactory;
use PHPUnit\Framework\TestCase;

final class ReadOnlyFilesystemFactoryTest extends TestCase
{
    public function testCreateLocalReturnsReadOnlyFilesystem(): void
    {
        $base = $this->createTempDir();

        try {
            $factory = new ReadOnlyFilesystemFactory(new LocalFilesystem($base));

            $filesystem = $factory->create(FilesystemType::Local, $base);

            $this->assertInstanceOf(ReadOnlyFilesystem::class, $filesystem);
            $this->assertInstanceOf(ReadOnlyFilesystemPortInterface::class, $filesystem);
        } finally {
            $this->removeDir($base);
        }
    }

    public function testCreateLocalWiresInjectedFilesystemAndBasePath(): void
    {
        $base = $this->createTempDir();

        try {
            $local = new LocalFilesystem($base);
            $factory = new ReadOnlyFilesystemFactory($local);

            $filesystem = $factory->create(FilesystemType::Local, $base);

            $this->assertSame($base . '/song.mp3', $filesystem->resolve('song.mp3'));
        } finally {
            $this->removeDir($base);
        }
    }

    public function testCreateReturnsFreshInstanceEachCall(): void
    {
        $base = $this->createTempDir();

        try {
            $factory = new ReadOnlyFilesystemFactory(new LocalFilesystem($base));

            $first = $factory->create(FilesystemType::Local, $base);
            $second = $factory->create(FilesystemType::Local, $base);

            $this->assertNotSame($first, $second);
        } finally {
            $this->removeDir($base);
        }
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/rofsfactory_' . uniqid('', true);
        mkdir($dir, 0o755, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
}
