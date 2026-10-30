<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\FileLock;
use Baander\Tooling\Filesystem\PrivateDirectory;
use PHPUnit\Framework\TestCase;

final class AtomicFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-filesystem-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testNativeDirectorySynchronizationIsSupported(): void
    {
        $stream = fopen($this->directory, 'r');
        self::assertIsResource($stream);
        self::assertTrue(fsync($stream));
        fclose($stream);
    }

    public function testReplacementKeepsOldReadersAndPublishesPrivateJson(): void
    {
        $path = $this->directory . '/instance/state.json';
        AtomicFile::writeJson($path, ['phase' => 'preparing']);
        $reader = fopen($path, 'r');
        AtomicFile::writeJson($path, ['phase' => 'ready']);
        self::assertSame(['phase' => 'ready'], AtomicFile::readJson($path));
        self::assertSame(
            ['phase' => 'preparing'],
            json_decode(stream_get_contents($reader), true, flags: JSON_THROW_ON_ERROR),
        );
        fclose($reader);
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(0700, fileperms(dirname($path)) & 0777);
        self::assertSame(['state.json'], array_values(array_diff(scandir(dirname($path)), ['.', '..'])));
    }

    public function testEncodingFailurePreservesExistingRecord(): void
    {
        $path = $this->directory . '/state.json';
        AtomicFile::writeJson($path, ['phase' => 'blocked']);
        try {
            AtomicFile::writeJson($path, ['invalid' => "\xff"]);
            self::fail('Invalid UTF-8 must fail.');
        } catch (\RuntimeException) {
            self::assertSame(['phase' => 'blocked'], AtomicFile::readJson($path));
        }
    }

    public function testFailedRenamePreservesDestinationAndCleansTemporaryFile(): void
    {
        mkdir($this->directory . '/state.json');
        file_put_contents($this->directory . '/state.json/evidence', 'keep');
        try {
            AtomicFile::write($this->directory . '/state.json', 'replacement');
            self::fail('A directory cannot be replaced by a state file.');
        } catch (\RuntimeException) {
            self::assertSame('keep', file_get_contents($this->directory . '/state.json/evidence'));
            self::assertSame(['state.json'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
        }
    }

    public function testCorruptJsonIsPreservedForRecovery(): void
    {
        $path = $this->directory . '/state.json';
        file_put_contents($path, '{');
        try {
            AtomicFile::readJson($path);
            self::fail('Corrupt JSON must fail.');
        } catch (\RuntimeException) {
            self::assertSame('{', file_get_contents($path));
        }
    }

    public function testSymlinkFilesAndParentsCannotEscapeStateDirectory(): void
    {
        mkdir($this->directory . '/outside');
        file_put_contents($this->directory . '/outside/state.json', '{"keep":true}');
        symlink($this->directory . '/outside/state.json', $this->directory . '/state.json');
        symlink($this->directory . '/outside', $this->directory . '/linked');
        foreach ([$this->directory . '/state.json', $this->directory . '/linked/state.json'] as $path) {
            foreach (['read', 'write', 'lock'] as $operation) {
                try {
                    match ($operation) {
                        'read' => AtomicFile::readJson($path),
                        'write' => AtomicFile::writeJson($path, ['escaped' => true]),
                        'lock' => FileLock::acquire($path),
                    };
                    self::fail('Symlink access must fail.');
                } catch (\RuntimeException) {
                    self::assertSame('{"keep":true}', file_get_contents($this->directory . '/outside/state.json'));
                }
            }
        }
    }

    public function testUntrustedWritableParentCannotPublishOrLockState(): void
    {
        $path = $this->directory . '/state.json';
        file_put_contents($path, '{"keep":true}');
        chmod($this->directory, 0777);
        foreach (['write', 'lock'] as $operation) {
            try {
                match ($operation) {
                    'write' => AtomicFile::write($path, 'replacement'),
                    'lock' => FileLock::acquire($this->directory . '/operation.lock'),
                };
                self::fail('A writable state directory must fail.');
            } catch (\RuntimeException) {
                self::assertSame('{"keep":true}', file_get_contents($path));
                self::assertFileDoesNotExist($this->directory . '/operation.lock');
            }
        }
    }

    public function testPrivateDirectoryRepairsPermissionsAndRejectsSymlinks(): void
    {
        mkdir($this->directory . '/instance', 0777);
        chmod($this->directory . '/instance', 0777);
        PrivateDirectory::prepare($this->directory . '/instance');
        clearstatcache();
        self::assertSame(0700, fileperms($this->directory . '/instance') & 0777);
        symlink($this->directory . '/instance', $this->directory . '/linked');
        $this->expectException(\RuntimeException::class);
        PrivateDirectory::prepare($this->directory . '/linked');
    }

    public function testProcessLockAllowsStatusButRefusesConcurrentMutationAndReleases(): void
    {
        AtomicFile::writeJson($this->directory . '/state.json', ['phase' => 'preparing']);
        $lock = FileLock::acquire($this->directory . '/operation.lock');
        [$exit, $stdout, $stderr] = $this->contender();
        self::assertSame(23, $exit);
        self::assertSame("preparing\n", $stdout);
        self::assertStringContainsString('another operation', $stderr);
        $lock->release();
        self::assertSame(0, $this->contender()[0]);
        $lock = FileLock::acquire($this->directory . '/operation.lock');
        unset($lock);
        self::assertSame(0, $this->contender()[0]);
        self::assertSame(0600, fileperms($this->directory . '/operation.lock') & 0777);
    }

    public function testSharedReadersCoexistAndNonblockingRetirementReportsContention(): void
    {
        $path = $this->directory . '/generation.lock';
        $first = FileLock::acquire($path, shared: true);
        $second = FileLock::acquire($path, shared: true);
        self::assertNull(FileLock::tryAcquire($path));
        $first->release();
        self::assertNull(FileLock::tryAcquire($path));
        $second->release();
        $exclusive = FileLock::tryAcquire($path);
        self::assertInstanceOf(FileLock::class, $exclusive);
        $exclusive->release();
        symlink($path, $this->directory . '/hostile.lock');
        $this->expectException(\RuntimeException::class);
        $unexpected = FileLock::tryAcquire($this->directory . '/hostile.lock');
        $unexpected?->release();
    }

    /** @return array{int, string, string} */
    private function contender(): array
    {
        $child = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/filesystem-lock.php', $this->directory],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($child), $stdout, $stderr];
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . '/' . $entry);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
