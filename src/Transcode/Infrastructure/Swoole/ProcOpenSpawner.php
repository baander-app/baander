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
    private const string FFMPEG_PATH = '/usr/bin/ffmpeg';

    /**
     * Cached proc_get_status() result per resource.
     *
     * proc_get_status() has a gotcha: the first call that reports
     * running=false triggers an internal pcntl_waitpid and the exit code is
     * captured, but subsequent calls return stale data (exitcode always -1,
     * running always false). We cache the first-seen terminal status so
     * exitCode() can still return the real code after isRunning() has run.
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
        $pid = $status !== false ? $status['pid'] : -1;

        return ['resource' => $resource, 'pipes' => $pipes, 'pid' => $pid];
    }

    public function isRunning(mixed $resource): bool
    {
        if (!is_resource($resource)) {
            return isset($this->terminalStatus[$this->key($resource)])
                ? $this->terminalStatus[$this->key($resource)]['running']
                : false;
        }

        $status = proc_get_status($resource);

        if ($status === false) {
            return false;
        }

        // Cache the terminal status on the first running=false observation.
        if (!$status['running'] && !isset($this->terminalStatus[$this->key($resource)])) {
            $this->terminalStatus[$this->key($resource)] = [
                'running' => false,
                'exitcode' => $status['exitcode'] ?? -1,
            ];
        }

        return $status['running'];
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
            proc_close($resource);
        }
    }
}
