<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Shared\Infrastructure\Swoole\Async;
use App\Transcode\Interface\Controller\StreamSegmentController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamSegmentControllerFileStabilityTest extends TestCase
{
    private string $directory;
    private string $path;
    private StreamSegmentController $controller;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-segment-stat-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->path = $this->directory . '/segment.m4s';
        file_put_contents($this->path, '0123456789');
        clearstatcache(true, $this->path);
        $this->controller = (new \ReflectionClass(StreamSegmentController::class))->newInstanceWithoutConstructor();
        // Load the async primitive before caching file stats; autoloading must
        // not accidentally invalidate the stale-cache reproduction.
        Async::inCoroutine();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    #[DataProvider('externalChanges')]
    public function testFastPathRejectsFilesChangedDuringItsSleep(string $change): void
    {
        $writer = $this->startWriter($change);
        $this->releaseWriter($writer);
        try {
            self::assertNull($this->invoke('isFileSizeStable', $this->path));
        } finally {
            $this->finishWriter($writer);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function externalChanges(): iterable
    {
        yield 'growing file' => ['grow-continuously'];
        yield 'removed file' => ['unlink'];
        yield 'same-size inode replacement' => ['replace-continuously'];
    }

    public function testFastPathRejectsCachedFileRemovedBeforeItsFirstRead(): void
    {
        $writer = $this->startWriter('unlink');
        try {
            self::assertSame(10, filesize($this->path));
            $this->releaseWriter($writer);
            self::assertSame("done\n", fgets($writer['output']));
            // The writer has its own stat cache; nothing here clears the parent's.
            self::assertNull($this->invoke('isFileSizeStable', $this->path));
        } finally {
            $this->closeWriter($writer);
        }
    }

    public function testFallbackDoesNotMistakeCachedSizeForACompletedGrowingFile(): void
    {
        $writer = $this->startWriter('grow-continuously');
        $this->releaseWriter($writer);
        try {
            self::assertNull($this->invoke('waitForFilePath', $this->path, 1));
        } finally {
            $this->finishWriter($writer);
        }
    }

    public function testStreamFileUsesFreshLengthAfterExternalGrowth(): void
    {
        $writer = $this->startWriter('grow-once');
        try {
            self::assertSame(10, filesize($this->path));
            $this->releaseWriter($writer);
            self::assertSame("done\n", fgets($writer['output']));
            $response = $this->invoke('streamFile', $this->path, 'video/mp4');
            self::assertInstanceOf(StreamedResponse::class, $response);
            self::assertSame('20', $response->headers->get('Content-Length'));
        } finally {
            $this->closeWriter($writer);
        }
    }

    public function testUnchangedNonEmptyFilePassesFastAndFallbackChecks(): void
    {
        self::assertSame($this->path, $this->invoke('isFileSizeStable', $this->path));
        self::assertSame($this->path, $this->invoke('waitForFilePath', $this->path, 2));
    }

    public function testDirectoryAndEmptyFileAreNotReady(): void
    {
        self::assertNull($this->invoke('isFileSizeStable', $this->directory));
        file_put_contents($this->path, '');
        clearstatcache(true, $this->path);
        self::assertNull($this->invoke('isFileSizeStable', $this->path));
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(StreamSegmentController::class, $method))->invoke($this->controller, ...$arguments);
    }

    /** @return array{process: resource, input: resource, output: resource, error: resource, continuous: bool} */
    private function startWriter(string $change): array
    {
        $code = <<<'WRITER'
$path = $argv[1];
$change = $argv[2];
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) !== "go\n") exit(2);
usleep(100000);
switch ($change) {
    case 'grow-once': file_put_contents($path, 'abcdefghij', FILE_APPEND); break;
    case 'unlink': unlink($path); break;
    case 'grow-continuously':
    case 'replace-continuously':
        stream_set_blocking(STDIN, false);
        $deadline = microtime(true) + 5;
        $i = 0;
        do {
            if ($change === 'grow-continuously') {
                file_put_contents($path, 'x', FILE_APPEND);
            } else {
                $replacement = $path . '.replacement';
                file_put_contents($replacement, 'abcdefghij');
                rename($path, $path . '.old.' . $i++);
                rename($replacement, $path);
            }
            usleep(50000);
            if (fgets(STDIN) === "stop\n" || feof(STDIN)) break;
        } while (microtime(true) < $deadline);
        break;
    default: exit(3);
}
fwrite(STDOUT, "done\n");
WRITER;
        $process = proc_open([PHP_BINARY, '-r', $code, $this->path, $change], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if ($process === false) {
            throw new \RuntimeException('Unable to start independent file writer');
        }
        stream_set_timeout($pipes[1], 2);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
        } catch (\Throwable $error) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            throw $error;
        }
        return ['process' => $process, 'input' => $pipes[0], 'output' => $pipes[1], 'error' => $pipes[2],
            'continuous' => str_ends_with($change, '-continuously')];
    }

    /** @param array{process: resource, input: resource, output: resource, error: resource, continuous: bool} $writer */
    private function releaseWriter(array $writer): void
    {
        fwrite($writer['input'], "go\n");
        fflush($writer['input']);
    }

    /** @param array{process: resource, input: resource, output: resource, error: resource, continuous: bool} $writer */
    private function finishWriter(array $writer): void
    {
        try {
            if ($writer['continuous']) {
                fwrite($writer['input'], "stop\n");
                fflush($writer['input']);
            }
            self::assertSame("done\n", fgets($writer['output']));
            self::assertSame('', stream_get_contents($writer['error']));
        } finally {
            $this->closeWriter($writer);
        }
    }
    /** @param array{process: resource, input: resource, output: resource, error: resource, continuous: bool} $writer */
    private function closeWriter(array $writer): void
    {
        proc_terminate($writer['process']);
        fclose($writer['input']);
        fclose($writer['output']);
        fclose($writer['error']);
        proc_close($writer['process']);
    }
}
