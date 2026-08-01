<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use RuntimeException;
use Swoole\Coroutine\System;

/**
 * Executes shell commands portably inside or outside a Swoole coroutine.
 *
 * Swoole\Coroutine\System::exec() only works inside a coroutine and its
 * signature differs between Swoole versions. This helper falls back to
 * proc_open when running outside the Swoole runtime so the same FFmpeg/FFprobe
 * adapters can be used from CLI scripts, tests, and Swoole workers.
 */
final class ProcessExecutor
{
    /**
     * Execute a command and return stdout, stderr, and exit code.
     *
     * @return array{code: int, output: string, error: string}
     */
    public static function exec(string $command, int $timeoutSeconds = 300): array
    {
        if (\Swoole\Coroutine::getCid() !== -1) {
            return self::execInCoroutine($command);
        }

        return self::execWithProcOpen($command, $timeoutSeconds);
    }

    /**
     * @return array{code: int, output: string, error: string}
     */
    private static function execInCoroutine(string $command): array
    {
        $result = System::exec($command, true);

        return [
            'code' => (int) ($result['code'] ?? -1),
            'output' => (string) ($result['output'] ?? ''),
            'error' => (string) ($result['error'] ?? ''),
        ];
    }

    /**
     * @return array{code: int, output: string, error: string}
     */
    private static function execWithProcOpen(string $command, int $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if ($process === false) {
            throw new RuntimeException('Failed to start process: ' . $command);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            if (($status['running'] ?? false) === false) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            if ((time() - $startTime) >= $timeoutSeconds) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new RuntimeException(sprintf('Process timed out after %d seconds: %s', $timeoutSeconds, $command));
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $changed = stream_select($read, $write, $except, 1);

            if ($changed > 0) {
                foreach ($read as $stream) {
                    $data = fread($stream, 65536);
                    if ($data !== false && $data !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $data;
                        } else {
                            $stderr .= $data;
                        }
                    }
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'code' => (int) ($status['exitcode'] ?? -1),
            'output' => $stdout,
            'error' => $stderr,
        ];
    }
}
