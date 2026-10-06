<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Infrastructure\Service;

use App\Filesystem\Infrastructure\Service\LocalFilesystem;
use App\Filesystem\Infrastructure\Service\ReadOnlyFilesystem;
use App\Filesystem\Infrastructure\Service\ReadOnlyFilesystemFactory;
use App\Shared\Domain\ValueObject\FilesystemType;
use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class FilesystemBoundaryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-filesystem-boundary-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory . '/library');
        mkdir($this->directory . '/library/inside');
        mkdir($this->directory . '/outside');
        file_put_contents($this->directory . '/library/inside/sentinel', 'inside');
        file_put_contents($this->directory . '/outside/sentinel', 'outside');
        symlink($this->directory . '/outside', $this->directory . '/library/external');
        symlink($this->directory . '/outside/missing-target', $this->directory . '/library/dangling');
        symlink($this->directory . '/library/inside', $this->directory . '/library/internal');
    }

    protected function tearDown(): void
    {
        $this->removeFixture($this->directory);
    }

    /** @return iterable<string, array{string, string}> */
    public static function localEscapeOperations(): iterable
    {
        foreach (['resolve', 'exists', 'size', 'read', 'write', 'open-read', 'open-write', 'open-append'] as $operation) {
            foreach (['external/sentinel', 'external/new/child', 'dangling/new/child'] as $path) {
                yield $operation . '/' . $path => [$operation, $path];
            }
        }
    }

    #[DataProvider('localEscapeOperations')]
    public function testEveryLocalOperationRejectsExternalSymlinksBeforeIo(string $operation, string $path): void
    {
        $filesystem = new LocalFilesystem($this->directory . '/library');

        $this->assertRejected(function () use ($filesystem, $operation, $path): void {
            match ($operation) {
                'resolve' => $filesystem->resolve($path),
                'exists' => $filesystem->exists($path),
                'size' => $filesystem->size($path),
                'read' => $filesystem->read($path),
                'write' => $filesystem->write($path, 'must not escape'),
                'open-read' => $filesystem->open($path, 'r')->close(),
                'open-write' => $filesystem->open($path, 'w')->close(),
                'open-append' => $filesystem->open($path, 'a')->close(),
                default => throw new LogicException('Unknown local filesystem fixture operation: ' . $operation),
            };
        });

        self::assertSame('outside', file_get_contents($this->directory . '/outside/sentinel'));
        self::assertFalse(file_exists($this->directory . '/outside/new'));
        self::assertFalse(file_exists($this->directory . '/outside/missing-target'));
    }

    /** @return iterable<string, array{string}> */
    public static function readOnlyOperations(): iterable
    {
        foreach (['resolve', 'exists', 'size', 'read', 'open', 'list', 'isDirectory', 'isFile', 'lastModified'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('readOnlyOperations')]
    public function testEveryReadOnlyOperationRejectsSiblingOutsideNarrowedLibrary(string $operation): void
    {
        $filesystem = new ReadOnlyFilesystem(new LocalFilesystem($this->directory), $this->directory . '/library');

        $this->assertRejected(function () use ($filesystem, $operation): void {
            match ($operation) {
                'resolve' => $filesystem->resolve('outside/sentinel'),
                'exists' => $filesystem->exists('outside/sentinel'),
                'size' => $filesystem->size('outside/sentinel'),
                'read' => $filesystem->read('outside/sentinel'),
                'open' => $filesystem->open('outside/sentinel')->close(),
                'list' => $filesystem->list('outside'),
                'isDirectory' => $filesystem->isDirectory('outside'),
                'isFile' => $filesystem->isFile('outside/sentinel'),
                'lastModified' => $filesystem->lastModified('outside/sentinel'),
                default => throw new LogicException('Unknown read-only filesystem fixture operation: ' . $operation),
            };
        });
    }

    public function testInternalSymlinksAndMissingDescendantsRemainWithinLibrary(): void
    {
        $local = new LocalFilesystem($this->directory . '/library');
        $readonly = new ReadOnlyFilesystem($local, $this->directory . '/library');

        self::assertTrue($readonly->exists('internal/sentinel'));
        self::assertSame(6, $readonly->size('internal/sentinel'));
        self::assertSame($this->directory . '/library/inside/sentinel', realpath($readonly->resolve('internal/sentinel')));
        self::assertFalse($readonly->exists('internal/new/child'));
        self::assertNotSame('', $readonly->resolve('internal/new/child'));
        self::assertNotSame('', $local->resolve('new/deep/child'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function readOnlySymlinkOperations(): iterable
    {
        foreach (self::readOnlyOperations() as [$operation]) {
            foreach (['external/sentinel', 'external/new/child', 'dangling/new/child'] as $path) {
                yield $operation . '/' . $path => [$operation, 'library/' . $path];
            }
        }
    }

    #[DataProvider('readOnlySymlinkOperations')]
    public function testReadOnlyBoundaryRejectsSymlinksAllowedByBroaderBackingRoot(string $operation, string $path): void
    {
        $filesystem = new ReadOnlyFilesystem(new LocalFilesystem($this->directory), $this->directory . '/library');

        $this->assertRejected(static function () use ($filesystem, $operation, $path): void {
            if ($operation === 'open') {
                $filesystem->open($path)->close();
            } else {
                $filesystem->{$operation}($path);
            }
        });
    }

    public function testConfiguredRootSymlinkAllowsExistingAndMissingInternalPaths(): void
    {
        $alias = $this->directory . '/root-alias';
        symlink($this->directory . '/library', $alias);
        $filesystem = new ReadOnlyFilesystem(new LocalFilesystem($alias), $alias);

        self::assertTrue($filesystem->exists('inside/sentinel'));
        self::assertSame($this->directory . '/library/inside/sentinel', realpath($filesystem->resolve('inside/sentinel')));
        self::assertFalse($filesystem->exists('future/deep/child'));
        self::assertNotSame('', $filesystem->resolve('future/deep/child'));
    }

    public function testRepointedWarmSymlinkCannotReuseCachedInternalResolution(): void
    {
        $filesystem = new LocalFilesystem($this->directory . '/library');
        self::assertSame($this->directory . '/library/inside/sentinel', realpath($filesystem->resolve('internal/sentinel')));
        unlink($this->directory . '/library/internal');
        symlink($this->directory . '/outside', $this->directory . '/library/internal');

        $this->assertRejected(static function () use ($filesystem): void {
            $filesystem->resolve('internal/sentinel');
        });
    }

    public function testLocalWriteCanCreateAMissingConfiguredRoot(): void
    {
        $filesystem = new LocalFilesystem($this->directory . '/new-root/deep');
        $this->runInCoroutine(static function () use ($filesystem): void {
            self::assertTrue($filesystem->write('created/file', 'created'));
        });

        self::assertSame('created', file_get_contents($this->directory . '/new-root/deep/created/file'));
    }

    public function testNullByteWriteIsRejectedBeforeCreatingDirectories(): void
    {
        $filesystem = new LocalFilesystem($this->directory . '/library');

        $this->assertRejected(static function () use ($filesystem): void {
            $filesystem->write("new-directory/\0file", 'must not write');
        });

        self::assertFalse(file_exists($this->directory . '/library/new-directory'));
    }

    public function testFactoryResolvesAndReadsRelativeToRequestedLibraryRoot(): void
    {
        $factory = new ReadOnlyFilesystemFactory();
        $filesystem = $factory->create(FilesystemType::Local, $this->directory . '/library');

        self::assertSame($this->directory . '/library/inside/sentinel', $filesystem->resolve('inside/sentinel'));
        $this->runInCoroutine(static function () use ($filesystem): void {
            self::assertSame('inside', $filesystem->read('inside/sentinel'));
        });
        $this->assertRejected(static function () use ($filesystem): void {
            $filesystem->resolve('external/sentinel');
        });
    }

    private function assertRejected(Closure $operation): void
    {
        try {
            $this->runInCoroutine($operation);
        } catch (Throwable $error) {
            // Report the unexpected error in full: an intermittent TypeError seen only in
            // full-suite runs could not be reproduced in isolation or under I/O load.
            self::assertInstanceOf(InvalidArgumentException::class, $error, sprintf(
                "Expected a boundary rejection, got %s: %s\n%s",
                $error::class,
                $error->getMessage(),
                $error->getTraceAsString(),
            ));
            return;
        }
        self::fail('The filesystem operation accepted a path outside its configured root.');
    }

    private function runInCoroutine(Closure $operation): void
    {
        $error = null;
        \Swoole\Coroutine\run(static function () use ($operation, &$error): void {
            try {
                $operation();
            } catch (Throwable $caught) {
                $error = $caught;
            }
        });
        if ($error !== null) {
            throw $error;
        }
    }

    private function removeFixture(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeFixture($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
