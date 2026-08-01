<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

/**
 * Abstracts process spawning so TranscodeStreamManager is unit-testable.
 *
 * The default implementation (ProcOpenSpawner) uses proc_open; tests inject
 * a stub that records calls without spawning real FFmpeg.
 */
interface ProcessSpawnerInterface
{
    /**
     * Spawn a long-running process from an argument array.
     *
     * The array form bypasses the shell entirely (no sh -c), eliminating the
     * command-injection surface. Each element is passed to execve as-is.
     *
     * @param list<string> $command Argv array; $command[0] is the executable
     * @return array{resource: mixed, pipes: array<int, mixed>, pid: int}
     */
    public function spawn(array $command): array;

    /**
     * Check whether the spawned process is still running.
     *
     * @param mixed $resource The resource returned by spawn()
     */
    public function isRunning(mixed $resource): bool;

    /**
     * Return the exit code of a process that has exited.
     *
     * Only meaningful once isRunning() has returned false. Returns -1 when
         * the exit code is unavailable (e.g. the process was signalled or the
     * status was already collected).
     *
     * @param mixed $resource The resource returned by spawn()
     */
    public function exitCode(mixed $resource): int;

    /**
     * Send a POSIX signal to the spawned process.
     *
     * @param int $pid Process PID returned by spawn()
     * @param int $signal e.g. SIGSTOP, SIGCONT, SIGKILL
     */
    public function signal(int $pid, int $signal): void;

    /**
     * Close the process and release file descriptors.
     *
     * @param mixed $resource The resource returned by spawn()
     */
    public function close(mixed $resource): void;
}
