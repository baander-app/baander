<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

/**
 * Default process spawner using proc_open.
 *
 * Provides non-blocking stdout/stderr pipes so the manager can poll for
 * availability while FFmpeg runs. Signal delivery uses posix_kill.
 */
final class ProcOpenSpawner implements ProcessSpawnerInterface
{
    /**
     * Swoole's coroutine process hooks can consume terminal status on the first
     * read, unlike native PHP 8.5. Retain it only until this handle is closed.
     *
     * @var array<int, array{running: bool, exitcode: int}>
     */
    private array $terminalStatus = [];

    /**
     * @param list<string> $command Argv array; $command[0] is the executable
     * @return array{resource: mixed, pipes: array<int, mixed>, pid: int}
     */
    public function spawn(array $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Array form: proc_open bypasses the shell entirely (direct execve),
        // so argument values cannot be interpreted as shell metacharacters.
        $resource = proc_open($command, $descriptors, $pipes);

        if ($resource === false) {
            throw new \RuntimeException('Failed to spawn FFmpeg process');
        }

        // Close stdin — we never send input to FFmpeg
        fclose($pipes[0]);
        unset($pipes[0]);

        // Make stdout/stderr non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($resource);
        $pid = $status['pid'];
        $this->rememberTerminalStatus($resource, $status);

        return ['resource' => $resource, 'pipes' => $pipes, 'pid' => $pid];
    }

    public function isRunning(mixed $resource): bool
    {
        if (!is_resource($resource)) {
            return false;
        }
        if (isset($this->terminalStatus[$this->key($resource)])) {
            return false;
        }

        $status = proc_get_status($resource);
        $this->rememberTerminalStatus($resource, $status);
        return $status['running'];
    }

    /** @param array{running: bool, exitcode: int} $status */
    private function rememberTerminalStatus(mixed $resource, array $status): void
    {
        if (!$status['running'] && !isset($this->terminalStatus[$this->key($resource)])) {
            $this->terminalStatus[$this->key($resource)] = [
                'running' => false,
                'exitcode' => $status['exitcode'],
            ];
        }
    }

    public function exitCode(mixed $resource): int
    {
        return $this->terminalStatus[$this->key($resource)]['exitcode'] ?? -1;
    }

    /**
     * Stable identifier for a proc resource (for array keying).
     */
    private function key(mixed $resource): int
    {
        return is_resource($resource) ? (int) $resource : -1;
    }

    public function signal(int $pid, int $signal): void
    {
        if ($pid > 0 && function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
        }
    }

    public function close(mixed $resource): void
    {
        if (is_resource($resource)) {
            $key = $this->key($resource);
            try {
                proc_close($resource);
            } finally {
                unset($this->terminalStatus[$key]);
            }
        }
    }
}
