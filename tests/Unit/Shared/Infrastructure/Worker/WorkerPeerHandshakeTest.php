<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\WorkerPeerHandshake;
use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use PHPUnit\Framework\TestCase;

final class WorkerPeerHandshakeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-peer-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $boot = $this->directory . '/' . str_repeat('a', 32);
        foreach (glob($boot . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($boot)) { rmdir($boot); }
        if (is_dir($this->directory)) { rmdir($this->directory); }
    }

    public function testConcurrentUnitsAcceptTheSameNewPrivateBootDirectory(): void
    {
        self::assertTrue(mkdir($this->directory, 0700));
        $code = <<<'PHP'
namespace App\Shared\Infrastructure\Worker;
function mkdir(string $path, int $mode, bool $recursive): bool
{
    $barrier = $GLOBALS['argv'][2];
    file_put_contents($barrier . '/arrived-' . $GLOBALS['argv'][3], 'ready');
    $deadline = hrtime(true) / 1e9 + 3;
    while (!is_file($barrier . '/arrived-control') || !is_file($barrier . '/arrived-execution')) {
        if (hrtime(true) / 1e9 >= $deadline) { throw new \RuntimeException('Concurrent directory barrier timed out.'); }
        clearstatcache();
        usleep(1000);
    }
    return \mkdir($path, $mode, $recursive);
}
require $argv[1] . '/vendor/autoload.php';
try {
    new WorkerPeerHandshake($argv[2], 'worker.baander.app', str_repeat('a', 32), $argv[3], hrtime(true) / 1e9);
    exit(0);
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(1);
}
PHP;
        $output = tmpfile();
        self::assertIsResource($output);
        $children = [];
        try {
            foreach (['control', 'execution'] as $unit) {
                $children[] = WorkerChildProcess::start([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 5), $this->directory, $unit], dirname(__DIR__, 5), $output, $output);
            }
            $deadline = hrtime(true) / 1e9 + 5;
            foreach ($children as $child) {
                while ($child->poll(hrtime(true) / 1e9)) {
                    if (hrtime(true) / 1e9 >= $deadline) {
                        self::fail('Concurrent boot directory creation did not finish.');
                    }
                    usleep(1000);
                }
                self::assertSame(0, $child->exitCode(), 'Both units must accept the directory created concurrently by their peer.');
            }
            self::assertSame(0700, fileperms($this->directory . '/' . str_repeat('a', 32)) & 0777);
        } finally {
            unset($child, $children);
            fclose($output);
            foreach (['control', 'execution'] as $unit) {
                $marker = $this->directory . '/arrived-' . $unit;
                if (is_file($marker)) { unlink($marker); }
            }
        }
    }

    public function testExistingDirectoryPermissionsAreRejectedWithoutChangingThem(): void
    {
        $boot = $this->directory . '/' . str_repeat('a', 32);
        self::assertTrue(mkdir($boot, 0755, true));
        try {
            $this->peer('control');
            self::fail('An existing directory accessible to other users cannot authorize worker IPC.');
        } catch (\RuntimeException) {
            self::assertSame(0755, fileperms($boot) & 0777);
        }
    }

    public function testPeerRequiresMatchingEpochAndLiveHeartbeat(): void
    {
        $control = $this->peer('control');
        $execution = $this->peer('execution');
        self::assertFalse($control->ready(4, 10));
        $execution->publish(4, true, false, 10);
        self::assertTrue($control->ready(4, 10.1));
        $this->expectException(\RuntimeException::class);
        $control->ready(4, 12);
    }

    public function testMismatchingEpochCannotAdmitBusiness(): void
    {
        $control = $this->peer('control');
        $this->peer('execution')->publish(5, true, false, 10);
        $this->expectException(\RuntimeException::class);
        $control->ready(4, 10);
    }

    public function testPeerDrainingStopsAdmissionImmediately(): void
    {
        $control = $this->peer('control');
        $execution = $this->peer('execution');
        $execution->publish(4, true, false, 10);
        self::assertTrue($control->ready(4, 10));
        $execution->publish(4, false, true, 10.1);
        $this->expectException(\RuntimeException::class);
        $control->ready(4, 10.1);
    }

    public function testMissingPeerFailsAtStartupDeadline(): void
    {
        $control = $this->peer('control');
        self::assertFalse($control->ready(null, 39.9));
        $this->expectException(\RuntimeException::class);
        $control->ready(null, 40);
    }

    public function testMalformedHeartbeatFailsClosed(): void
    {
        $control = $this->peer('control');
        file_put_contents($this->directory . '/' . str_repeat('a', 32) . '/execution.json', '{}');
        chmod($this->directory . '/' . str_repeat('a', 32) . '/execution.json', 0600);
        $this->expectException(\RuntimeException::class);
        $control->ready(null, 10);
    }

    private function peer(string $unit): WorkerPeerHandshake
    {
        return new WorkerPeerHandshake($this->directory, 'worker.baander.app', str_repeat('a', 32), $unit, 10);
    }
}
