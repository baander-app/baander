<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control;

use App\Shared\Infrastructure\Swoole\Control\ControlSocketDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ControlSocketDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-control-directory-test-' . getmypid();
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (['link', 'owned/nested', 'owned', 'created'] as $entry) {
            $path = $this->root . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        rmdir($this->root);
    }

    public function testCreatesAMissingDirectoryWithMode0700(): void
    {
        $directory = $this->root . '/created';

        ControlSocketDirectory::prepare($directory, fileowner($this->root));

        clearstatcache(true, $directory);
        self::assertDirectoryExists($directory);
        self::assertSame(0700, fileperms($directory) & 07777);
    }

    public function testRestrictsAnExistingDirectoryOwnedByTheServerUser(): void
    {
        $directory = $this->root . '/owned';
        mkdir($directory, 0755);
        chmod($directory, 0755);

        ControlSocketDirectory::prepare($directory, fileowner($directory));

        clearstatcache(true, $directory);
        self::assertSame(0700, fileperms($directory) & 07777);
    }

    public function testRefusesADirectoryAnotherUserOwnsEvenWhenItsModeCanBeChanged(): void
    {
        // Under root, chmod succeeds on any directory; the owner must still match.
        $directory = $this->root . '/owned';
        mkdir($directory, 0755);
        chmod($directory, 0755);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a directory owned by the server user');

        try {
            ControlSocketDirectory::prepare($directory, (int) fileowner($directory) + 1);
        } finally {
            clearstatcache(true, $directory);
            self::assertSame(0755, fileperms($directory) & 07777);
        }
    }

    public function testRefusesASymlinkedDirectory(): void
    {
        mkdir($this->root . '/owned', 0700);
        symlink($this->root . '/owned', $this->root . '/link');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a directory owned by the server user');

        ControlSocketDirectory::prepare($this->root . '/link', fileowner($this->root . '/owned'));
    }

    public function testWithoutAnEffectiveUidRefusesADirectoryWhoseModeCannotBeChanged(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a directory owned by the server user');

        ControlSocketDirectory::prepare('/', null);
    }
}
