<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionProtocol;
use App\Shared\Infrastructure\Worker\OutputTail;
use RuntimeException;

/** Executes media work remotely in admitted workers and locally in standalone tools. */
final class ProcessExecutor
{
    /** Standard output past this many bytes fails a local command: callers parse it whole. */
    public const int OUTPUT_BYTES = NativeExecutionProtocol::OUTPUT_BYTES;

    /** Standard error keeps its newest bytes only: callers report its end. */
    public const int ERROR_TAIL_BYTES = NativeExecutionProtocol::ERROR_TAIL_BYTES;

    private static ?NativeExecutionClientInterface $nativeExecution = null;
    private static bool $nativeRequired = false;

    public static function configureNativeExecution(?NativeExecutionClientInterface $client): void
    {
        self::$nativeExecution = $client;
    }

    public static function requireNativeExecution(): void
    {
        self::$nativeRequired = true;
    }

    public static function usesNativeExecution(): bool
    {
        return self::$nativeRequired || self::$nativeExecution !== null;
    }

    /**
     * Execute a command and return stdout, stderr, and exit code.
     *
     * @param string|list<string> $command
     * @param null|callable(): bool $continue
     * @return array{code: int, output: string, error: string}
     */
    public static function exec(string|array $command, int $timeoutSeconds = 300, ?callable $continue = null): array
    {
        if (self::$nativeExecution !== null) {
            if (!is_array($command)) {
                throw new RuntimeException('Remote media execution requires an argument array.');
            }
            return self::execNative($command, $timeoutSeconds, $continue);
        }
        if (self::$nativeRequired) {
            throw new RuntimeException('The worker native execution client is unavailable.');
        }
        return self::execWithProcOpen($command, $timeoutSeconds);
    }

    /**
     * @param list<string> $command
     * @param null|callable(): bool $continue
     * @return array{code: int, output: string, error: string}
     */
    public static function execNative(array $command, int $timeoutSeconds, ?callable $continue): array
    {
        $client = self::$nativeExecution ?? throw new NativeExecutionException('Native execution is unavailable.', uncertain: false);
        $handle = $client->submit($command, $timeoutSeconds);
        $terminal = false;
        try {
            while (true) {
                if ($continue !== null && !$continue()) {
                    return ['code' => -1, 'output' => '', 'error' => 'Media execution stopped.'];
                }
                $status = $client->inspect($handle);
                if (($status['state'] ?? '') === 'exited') {
                    $terminal = true;
                    return [
                        'code' => (int) ($status['exitCode'] ?? -1),
                        'output' => (string) ($status['stdout'] ?? ''),
                        'error' => self::errorText((string) ($status['stderr'] ?? ''), $status['error'] ?? null),
                    ];
                }
                if (($status['state'] ?? '') !== 'running') {
                    throw new NativeExecutionException('Native media execution state is unknown.');
                }
                Async::sleep(0.05);
            }
        } finally {
            if (!$terminal) {
                $client->signal($handle, 'stop');
                $deadline = hrtime(true) + 1_000_000_000;
                do {
                    $status = $client->inspect($handle);
                    if (($status['state'] ?? '') === 'exited') {
                        $terminal = true;
                        break;
                    }
                    if (($status['state'] ?? '') !== 'running') {
                        throw new NativeExecutionException('Native media termination state is unknown.');
                    }
                    Async::sleep(0.05);
                } while (hrtime(true) < $deadline);
                if (!$terminal) {
                    throw new NativeExecutionException('Native media execution did not confirm termination.');
                }
            }
            if (!$client->release($handle)) {
                throw new NativeExecutionException('Native media execution did not confirm release.');
            }
        }
    }

    /**
     * @param string|list<string> $command
     * @return array{code: int, output: string, error: string}
     */
    private static function execWithProcOpen(string|array $command, int $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if ($process === false) {
            throw new RuntimeException('Failed to start media process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = new OutputTail(self::ERROR_TAIL_BYTES);
        $exitCode = null;
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr->append((string) stream_get_contents($pipes[2]));
                $exitCode = $status['exitcode'];
                break;
            }

            if ((time() - $startTime) >= $timeoutSeconds) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new RuntimeException(sprintf('Media process timed out after %d seconds.', $timeoutSeconds));
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
                            $stderr->append($data);
                        }
                    }
                }
            }
            if (strlen($stdout) > self::OUTPUT_BYTES) {
                proc_terminate($process, 9);
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // The read after the command exited can pass the bound as well.
        if (strlen($stdout) > self::OUTPUT_BYTES) {
            return ['code' => -1, 'output' => '', 'error' => self::errorText(
                $stderr->contents(),
                sprintf('Media process output exceeded %d bytes.', self::OUTPUT_BYTES),
            )];
        }

        return [
            'code' => $exitCode ?? -1,
            'output' => $stdout,
            'error' => $stderr->contents(),
        ];
    }

    /**
     * A command's error output followed by the reason it failed, when there is one. The reason
     * goes last, where callers that keep the end of the error text find it.
     */
    public static function errorText(string $stderr, ?string $reason): string
    {
        return match (true) {
            $reason === null || $reason === '' => $stderr,
            $stderr === '' => $reason,
            default => rtrim($stderr, "\n") . "\n" . $reason,
        };
    }
}
