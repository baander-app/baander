<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Storage;

use App\Media\Infrastructure\Storage\FlysystemStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class StorageBoundaryTest extends TestCase
{
    private string $directory;
    private FlysystemStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/storage-boundary-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/root', 0755, true);
        mkdir($this->directory . '/root-other');
        file_put_contents($this->directory . '/root-other/cover.jpg', 'private');
        file_put_contents($this->directory . '/root-other/cover.webp', 'private');
        symlink($this->directory . '/root-other', $this->directory . '/root/link');
        $this->storage = new FlysystemStorage($this->directory . '/root');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /** @return iterable<string, array{string, string}> */
    public static function escapeOperations(): iterable
    {
        foreach (['delete', 'exists', 'fullPath', 'resolve', 'deleteDerived', 'store', 'storeFromBytes'] as $operation) {
            foreach (['../root-other/cover.jpg', 'link/cover.jpg', 'link/missing/sub/file.jpg'] as $path) {
                yield $operation . ':' . $path => [$operation, $path];
            }
        }
    }

    #[DataProvider('escapeOperations')]
    public function testEveryOperationRejectsAnEscape(string $operation, string $path): void
    {
        try {
            match ($operation) {
                'store' => $this->storage->store($this->directory . '/root-other/cover.jpg', $path),
                'storeFromBytes' => $this->storage->storeFromBytes('replacement', $path),
                'deleteDerived' => $this->storage->deleteDerived($path, 'jpg'),
                default => $this->storage->{$operation}($path),
            };
            self::fail('Storage accepted a path outside its root');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Path traversal detected', $error->getMessage());
        }
        self::assertSame('private', file_get_contents($this->directory . '/root-other/cover.jpg'));
        self::assertSame('private', file_get_contents($this->directory . '/root-other/cover.webp'));
        self::assertDirectoryDoesNotExist($this->directory . '/root-other/missing');
    }

    public function testInvalidWriteDoesNotCreateDirectories(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->storage->storeFromBytes('x', '../escape/new/file');
        } finally {
            self::assertDirectoryDoesNotExist($this->directory . '/escape');
        }
    }

    public function testDerivedSymlinkIsValidatedBeforeDeletion(): void
    {
        file_put_contents($this->directory . '/root/cover.jpg', 'original');
        symlink($this->directory . '/root-other/cover.webp', $this->directory . '/root/cover.webp');
        $this->expectException(\RuntimeException::class);
        $this->storage->deleteDerived('cover.jpg', 'jpg');
    }

    public function testReadOfMissingPathDoesNotCreateDirectories(): void
    {
        self::assertFalse($this->storage->exists('missing/file'));
        self::assertDirectoryDoesNotExist($this->directory . '/root/missing');
    }
}
