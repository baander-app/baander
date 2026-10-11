<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

/**
 * The drain's backstop, seen from the master: a separate process that SIGKILLs the given
 * processes and process groups once the drain deadline has passed.
 *
 * A PID 1 master cannot SIGKILL itself, and once the drain window closes it blocks in waitpid on
 * the manager, where no timer of its own can fire. Killing the manager ends that wait.
 *
 * The process is started through proc_open and bash, which closes every inherited descriptor
 * above stderr before it executes the watchdog command, so the watchdog holds none of the
 * master's sockets or pipes. Swoole's coroutine hook for proc_open is suspended while it starts:
 * the hooked variant would keep the process in the coroutine runtime.
 */
final class DrainWatchdog
{
    /** The watchdog command's exit code after it has killed the processes. */
    public const int EXIT_FIRED = 3;

    private const string CLOSE_INHERITED_DESCRIPTORS = 'for fd in /proc/$$/fd/*; do fd=${fd##*/}; '
        . 'if [ "$fd" -gt 2 ]; then eval "exec $fd>&-"; fi; done; exec "$@"';

    /**
     * Held until the watchdog has been reaped: freeing it earlier would run proc_close, which
     * waits for the process to exit.
     *
     * @var resource|null
     */
    private $process;

    /** @param resource $process */
    private function __construct($process, public readonly int $pid, private readonly int $deadlineNs)
    {
        $this->process = $process;
    }

    /**
     * Starts `$command --deadline-ns=... --pid=... --pgid=...`, where $command is the watchdog
     * command line, e.g. [PHP_BINARY, 'bin/console', 'app:server:drain:watch'].
     *
     * @param list<string> $command
     * @param list<int> $pids
     * @param list<int> $processGroups
     */
    public static function start(array $command, int $deadlineNs, array $pids, array $processGroups): self
    {
        $arguments = [...$command, '--deadline-ns=' . $deadlineNs];
        foreach ($pids as $pid) {
            $arguments[] = '--pid=' . $pid;
        }
        foreach ($processGroups as $processGroup) {
            $arguments[] = '--pgid=' . $processGroup;
        }

        $hooks = self::suspendProcessHook();
        try {
            $process = proc_open(
                ['/bin/bash', '-c', self::CLOSE_INHERITED_DESCRIPTORS, 'drain-watchdog', ...$arguments],
                [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Cannot start the drain watchdog.');
            }
            $pid = proc_get_status($process)['pid'];
        } finally {
            self::restoreProcessHook($hooks);
        }

        return new self($process, $pid, $deadlineNs);
    }

    /**
     * Stops the watchdog if it is still waiting, and reports whether the deadline was reached:
     * the watchdog killed the processes, or would have. Call it once.
     */
    public function end(): bool
    {
        $fired = false;
        $exit = $this->reap();
        if ($exit === null) {
            posix_kill($this->pid, SIGTERM);
            $giveUpAt = hrtime(true) + 1_000_000_000;
            while (($exit = $this->reap()) === null && hrtime(true) < $giveUpAt) {
                usleep(10_000);
            }
            if ($exit === null) {
                posix_kill($this->pid, SIGKILL);
                $this->reap(blocking: true);
            }
        } else {
            $fired = $exit === self::EXIT_FIRED;
        }
        // Dropping the handle runs proc_close, which finds the child reaped and returns at once.
        $process = $this->process;
        $this->process = null;
        unset($process);

        return $fired || hrtime(true) >= $this->deadlineNs;
    }

    /** The exit code once the watchdog has exited (-1 for a signal or an unknown status), else null. */
    private function reap(bool $blocking = false): ?int
    {
        $status = 0;
        $result = pcntl_waitpid($this->pid, $status, $blocking ? 0 : WNOHANG);
        if ($result === 0) {
            return null;
        }
        if ($result === $this->pid && pcntl_wifexited($status)) {
            return pcntl_wexitstatus($status);
        }

        // A signal, or a child already reaped by someone else (ECHILD).
        return -1;
    }

    private static function suspendProcessHook(): ?int
    {
        if (!class_exists(\Swoole\Runtime::class)) {
            return null;
        }
        $flags = \Swoole\Runtime::getHookFlags();
        if (($flags & SWOOLE_HOOK_PROC) === 0) {
            return null;
        }
        \Swoole\Runtime::setHookFlags($flags & ~SWOOLE_HOOK_PROC);

        return $flags;
    }

    private static function restoreProcessHook(?int $flags): void
    {
        if ($flags !== null) {
            \Swoole\Runtime::setHookFlags($flags);
        }
    }
}
