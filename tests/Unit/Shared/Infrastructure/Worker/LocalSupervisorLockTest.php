<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\LocalSupervisorLock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalSupervisorLockTest extends TestCase
{
    private string $directory;
    /** @var list<resource> */
    private array $processes = [];
    /** @var list<resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-supervisor-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    public function testRealSecondProcessCannotAcquireUntilExplicitRelease(): void
    {
        $lock = LocalSupervisorLock::acquire($this->directory, 'messenger');
        $path = $this->directory . '/messenger.lock';
        $inode = fileinode($path);
        [$process, $pipes] = $this->startChild(false);
        self::assertStringContainsString('Supervisor already running: messenger', $this->line($pipes[1]));
        self::assertSame(1, proc_close($process));
        $lock->release();
        $lock->release();
        self::assertFileExists($path);
        self::assertSame($inode, fileinode($path));
        [$process, $pipes] = $this->startChild(false);
        self::assertSame("acquired\n", $this->line($pipes[1]));
        self::assertSame(0, proc_close($process));
        self::assertSame($inode, fileinode($path));
    }

    public function testProcessExitReleasesLockWithoutDeletingItsFile(): void
    {
        [$process, $pipes] = $this->startChild(true);
        self::assertSame("acquired\n", $this->line($pipes[1]));
        $this->assertContended();
        fwrite($pipes[0], "exit\n");
        self::assertSame(0, proc_close($process));
        self::assertFileExists($this->directory . '/messenger.lock');
        $lock = LocalSupervisorLock::acquire($this->directory, 'messenger');
        $lock->release();
    }

    public function testKilledProcessAlsoReleasesKernelLock(): void
    {
        [$process, $pipes] = $this->startChild(true);
        self::assertSame("acquired\n", $this->line($pipes[1]));
        $this->assertContended();
        self::assertTrue(proc_terminate($process, 9));
        proc_close($process);
        self::assertFileExists($this->directory . '/messenger.lock');
        $lock = LocalSupervisorLock::acquire($this->directory, 'messenger');
        $lock->release();
    }

    #[DataProvider('lockCreationModes')]
    public function testExecChildDoesNotRetainLockAfterSupervisorDies(bool $existing): void
    {
        if ($existing) {
            touch($this->directory . '/messenger.lock');
            chmod($this->directory . '/messenger.lock', 0600);
        }
        $code = <<<'PHP'
require $argv[1];
$lock = \App\Shared\Infrastructure\Worker\LocalSupervisorLock::acquire($argv[2], "messenger");
$child = proc_open([PHP_BINARY, "-r", 'echo "alive\n"; sleep(30);'], [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
if (fgets($pipes[1]) !== "alive\n") { exit(2); }
echo proc_get_status($child)["pid"]."\n";
fgets(STDIN);
PHP;
        [$process, $pipes] = $this->spawn($code, 'wait');
        $childPid = (int) trim($this->line($pipes[1]));
        self::assertGreaterThan(0, $childPid);
        try {
            $this->assertContended();
            self::assertTrue(proc_terminate($process, 9));
            proc_close($process);
            self::assertTrue(posix_kill($childPid, 0), 'Separately execed child is still alive after supervisor death.');
            $replacement = LocalSupervisorLock::acquire($this->directory, 'messenger');
            $replacement->release();
        } finally {
            posix_kill($childPid, 9);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function lockCreationModes(): iterable
    {
        yield 'create exclusive' => [false];
        yield 'open persistent' => [true];
    }

    public function testDestructorReleasesAndDifferentNamesAreIndependent(): void
    {
        $first = LocalSupervisorLock::acquire($this->directory, 'messenger');
        $second = LocalSupervisorLock::acquire($this->directory, 'outbox');
        unset($first);
        gc_collect_cycles();
        $replacement = LocalSupervisorLock::acquire($this->directory, 'messenger');
        $replacement->release();
        $second->release();
        self::assertFileExists($this->directory . '/outbox.lock');
    }

    #[DataProvider('invalidNames')]
    public function testRejectsUnsafeName(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LocalSupervisorLock::acquire($this->directory, $name);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../messenger'];
        yield 'absolute' => ['/messenger'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    public function testRejectsUntrustedWritableDirectory(): void
    {
        chmod($this->directory, 0770);
        $this->expectException(\InvalidArgumentException::class);
        LocalSupervisorLock::acquire($this->directory, 'messenger');
    }

    public function testRejectsPrivateDirectoryUnderUntrustedWritableParent(): void
    {
        $child = $this->directory . '/private';
        mkdir($child, 0700);
        chmod($this->directory, 0777);
        try {
            LocalSupervisorLock::acquire($child, 'messenger');
            self::fail('A private child of a non-sticky writable parent is unsafe.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('untrusted parent', $error->getMessage());
        } finally {
            rmdir($child);
        }
    }

    public function testRejectsSymlinkDirectory(): void
    {
        symlink($this->directory, $this->directory . '/alias');
        $this->expectException(\InvalidArgumentException::class);
        LocalSupervisorLock::acquire($this->directory . '/alias', 'messenger');
    }

    public function testRejectsMissingAndRelativeDirectory(): void
    {
        foreach ([$this->directory . '/missing', 'relative', ''] as $directory) {
            try {
                LocalSupervisorLock::acquire($directory, 'messenger');
                self::fail('Invalid lock directory must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('directory', $error->getMessage());
            }
        }
    }

    public function testRejectsSymlinkLockWithoutTouchingTarget(): void
    {
        file_put_contents($this->directory . '/target', 'preserve');
        symlink($this->directory . '/target', $this->directory . '/messenger.lock');
        try {
            LocalSupervisorLock::acquire($this->directory, 'messenger');
            self::fail('Unsafe lock file must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unsafe', $error->getMessage());
        }
        self::assertSame('preserve', file_get_contents($this->directory . '/target'));
    }

    public function testRejectsWritableExistingLockAndPreservesContents(): void
    {
        $path = $this->directory . '/messenger.lock';
        file_put_contents($path, 'preserve');
        chmod($path, 0660);
        try {
            LocalSupervisorLock::acquire($this->directory, 'messenger');
            self::fail('Unsafe lock file must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unsafe', $error->getMessage());
        }
        self::assertSame('preserve', file_get_contents($path));
    }

    private function assertContended(): void
    {
        try {
            LocalSupervisorLock::acquire($this->directory, 'messenger');
            self::fail('Concurrent owner must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('Supervisor already running: messenger', $error->getMessage());
        }
    }

    /** @return array{resource, array<int, resource>} */
    private function startChild(bool $wait): array
    {
        $code = 'require $argv[1]; try { $lock = \\App\\Shared\\Infrastructure\\Worker\\LocalSupervisorLock::acquire($argv[2], "messenger"); echo "acquired\\n"; if ($argv[3] === "wait") { fgets(STDIN); } } catch (\\Throwable $e) { echo $e->getMessage()."\\n"; exit(1); }';
        return $this->spawn($code, $wait ? 'wait' : 'exit');
    }

    /** @return array{resource, array<int, resource>} */
    private function spawn(string $code, string $mode): array
    {
        $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 5) . '/vendor/autoload.php', $this->directory, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
        foreach ($pipes as $pipe) {
            $this->pipes[] = $pipe;
            stream_set_timeout($pipe, 3);
        }
        return [$process, $pipes];
    }

    /** @param resource $pipe */
    private function line(mixed $pipe): string
    {
        $line = fgets($pipe);
        self::assertIsString($line, 'Child must report lock result within three seconds.');
        return $line;
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }
}
