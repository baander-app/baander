<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

use Baander\Tooling\Filesystem\PrivateDirectory;
use RuntimeException;
use Swoole\Process;

final class RunningProcess
{
    private const float TERMINATION_GRACE = 0.25;
    private const float PIPE_DRAIN_GRACE = 0.25;

    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes;
    /** @var resource|null */
    private $log = null;
    private float $started;
    private ?float $stopping = null;
    private ?float $exited = null;
    private bool $killed = false;
    private bool $timedOut = false;
    private bool $cancelled = false;
    private int $inputOffset = 0;
    private string $stdout = '';
    private string $stderr = '';
    private ?int $exitCode = null;
    private ?int $signal = null;
    private ?int $group = null;
    private int $pid;
    private bool $startedProcess = false;
    private ?ProcessResult $result = null;

    public function __construct(private readonly ProcessSpec $spec)
    {
        $this->started = self::now();
        if ($spec->logPath !== null) {
            $this->openLog($spec->logPath);
        }
        $descriptors = $spec->passthrough
            ? [0 => STDIN, 1 => STDOUT, 2 => STDERR]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $argv = $spec->argv;
        if ($spec->processGroup) {
            $argv = ['setsid', 'bash', dirname(__DIR__) . '/process-group.sh', ...$argv];
            $descriptors[3] = ['pipe', 'w'];
            $descriptors[4] = ['pipe', 'r'];
        }
        $process = proc_open($argv, $descriptors, $pipes, $spec->cwd, $spec->environment, ['bypass_shell' => true]);
        if ($process === false) {
            $this->closeLog();
            throw new RuntimeException('Cannot start process: ' . $spec->argv[0]);
        }
        $this->process = $process;
        $this->startedProcess = true;
        $this->pipes = $pipes;
        $this->pid = proc_get_status($process)['pid'];
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        if ($spec->processGroup) {
            try {
                $this->verifyGroup();
            } catch (\Throwable $error) {
                $this->cancel();
                $this->dispose();
                throw $error;
            }
        }
    }

    public function cancel(int $signal = 15): void
    {
        if ($this->result !== null || $this->stopping !== null) {
            return;
        }
        $this->cancelled = true;
        $this->stop($signal);
    }

    /** @internal Serviced by the owning runner's local reactor. */
    public function pump(): void
    {
        if ($this->result !== null) {
            return;
        }
        $now = self::now();
        $this->drain(1);
        $this->drain(2);
        if (isset($this->pipes[0])) {
            $input = $this->spec->stdin ?? '';
            if ($this->inputOffset < strlen($input)) {
                $written = @fwrite($this->pipes[0], substr($input, $this->inputOffset, 65_536));
                if ($written === false) {
                    $this->closePipe(0);
                } else {
                    $this->inputOffset += $written;
                }
            }
            if ($this->inputOffset >= strlen($input)) {
                $this->closePipe(0);
            }
        }
        if ($this->exited === null) {
            if ($this->group !== null) {
                // Leave the leader waitable until the final group signal. Its PID
                // cannot be recycled into an unrelated group while it is a zombie.
                $stat = file_get_contents('/proc/' . $this->pid . '/stat');
                $ended = $stat !== false && substr($stat, (int) strrpos($stat, ')') + 2, 1) === 'Z';
            } else {
                $status = proc_get_status($this->process);
                $ended = !$status['running'];
                if ($ended) {
                    $this->cacheStatus($status);
                }
            }
            if ($ended) {
                $this->exited = $now;
                $this->closePipe(0);
                if ($this->group !== null && $this->stopping === null) {
                    $this->stop(15);
                }
            }
        }
        if ($this->exited === null && $this->stopping === null && $this->spec->timeout !== null && $now - $this->started >= $this->spec->timeout) {
            $this->timedOut = true;
            $this->stop(15);
        }
        if ($this->stopping !== null && !$this->killed && $now - $this->stopping >= self::TERMINATION_GRACE) {
            $this->sendSignal(9);
            $this->killed = true;
        }
        if ($this->exited !== null) {
            $drained = !isset($this->pipes[1]) && !isset($this->pipes[2]);
            $groupSettled = $this->group === null || $this->killed;
            if (($drained && $groupSettled) || $now - $this->exited >= self::PIPE_DRAIN_GRACE + self::TERMINATION_GRACE) {
                if ($this->group !== null && !$this->killed) {
                    $this->sendSignal(9);
                }
                $this->finish();
            }
        }
    }

    /** @internal */
    public function result(): ?ProcessResult
    {
        return $this->result;
    }

    private function verifyGroup(): void
    {
        $identity = '';
        $deadline = self::now() + 1;
        while (!str_contains($identity, "\n") && self::now() < $deadline) {
            $chunk = fread($this->pipes[3], 64);
            if ($chunk !== false) {
                $identity .= $chunk;
            }
            if (feof($this->pipes[3])) {
                break;
            }
            usleep(1000);
        }
        $this->closePipe(3);
        $stat = @file_get_contents('/proc/' . $this->pid . '/stat');
        // Fields after comm are state, parent PID, process group and session.
        $fields = $stat === false ? [] : explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
        if (trim($identity) !== (string) $this->pid || ($fields[2] ?? '') !== (string) $this->pid || ($fields[3] ?? '') !== (string) $this->pid) {
            throw new RuntimeException('Process-group launcher did not establish an owned session.');
        }
        $this->group = $this->pid;
        if (fwrite($this->pipes[4], "owned\n") !== 6) {
            throw new RuntimeException('Cannot acknowledge owned process group.');
        }
        $this->closePipe(4);
    }

    private function stop(int $signal): void
    {
        $this->stopping = self::now();
        $this->sendSignal($signal);
        $this->closePipe(0);
    }

    private function sendSignal(int $signal): void
    {
        // A group can disappear between observing its leader and escalation.
        // Consume only this call's warning; other failures remain actionable.
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $sent = Process::kill($this->group === null ? $this->pid : -$this->group, $signal);
        } finally {
            restore_error_handler();
        }
        if (!$sent && !str_contains($warning, 'No such process[3]')) {
            throw new RuntimeException('Cannot signal owned process: ' . $warning);
        }
    }

    private function drain(int $descriptor): void
    {
        if (!isset($this->pipes[$descriptor])) {
            return;
        }
        // Bound each turn so a continuously writing child cannot starve deadlines.
        $chunk = stream_get_contents($this->pipes[$descriptor], 262_144);
        if ($chunk !== false && $chunk !== '') {
            if ($this->spec->logPath === null) {
                if ($descriptor === 1) {
                    $this->stdout .= $chunk;
                } else {
                    $this->stderr .= $chunk;
                }
            }
            if ($this->log !== null) {
                $remaining = $chunk;
                while ($remaining !== '') {
                    $written = fwrite($this->log, $remaining);
                    if ($written === false || $written === 0) {
                        $this->closeLog();
                        throw new RuntimeException('Cannot write process log.');
                    }
                    $remaining = substr($remaining, $written);
                }
            }
        }
        if (feof($this->pipes[$descriptor])) {
            $this->closePipe($descriptor);
        }
    }

    /** @param array{exitcode: int, signaled: bool, termsig: int, ...} $status */
    private function cacheStatus(array $status): void
    {
        $this->exitCode = $status['exitcode'];
        $this->signal = $status['signaled'] ? $status['termsig'] : null;
    }

    private function finish(): void
    {
        if ($this->group !== null) {
            $this->cacheStatus(proc_get_status($this->process));
        }
        foreach (array_keys($this->pipes) as $descriptor) {
            $this->closePipe($descriptor);
        }
        $closedCode = proc_close($this->process);
        $this->closeLog();
        $this->result = new ProcessResult(
            exitCode: $this->signal !== null ? 128 + $this->signal : ($this->exitCode ?? $closedCode),
            signal: $this->signal,
            timedOut: $this->timedOut,
            cancelled: $this->cancelled,
            stdout: $this->stdout,
            stderr: $this->stderr,
            duration: self::now() - $this->started,
        );
    }

    private function closePipe(int $descriptor): void
    {
        if (isset($this->pipes[$descriptor])) {
            fclose($this->pipes[$descriptor]);
            unset($this->pipes[$descriptor]);
        }
    }

    private function openLog(string $path): void
    {
        PrivateDirectory::ensure(dirname($path));
        PrivateDirectory::assertNoSymlinks($path);
        $mask = umask(0077);
        try {
            $log = fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        if ($log === false) {
            throw new RuntimeException('Cannot open process log: ' . $path);
        }
        $this->log = $log;
        $opened = fstat($log);
        $named = lstat($path);
        $owner = stat('/proc/self');
        if ($opened === false || $named === false || $owner === false
            || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
            || ($opened['mode'] & 0170000) !== 0100000 || $opened['uid'] !== $owner['uid']
        ) {
            $this->closeLog();
            throw new RuntimeException('Process log must be an owned regular file: ' . $path);
        }
        if (!chmod($path, 0600) || !ftruncate($log, 0)) {
            $this->closeLog();
            throw new RuntimeException('Cannot prepare private process log: ' . $path);
        }
    }

    private function closeLog(): void
    {
        if (is_resource($this->log)) {
            fclose($this->log);
        }
        $this->log = null;
    }

    /** @internal Release a child when its owner's wait fails. */
    public function dispose(): void
    {
        $this->cancel();
        $this->closeLog();
        $deadline = self::now() + 1;
        while ($this->result === null && self::now() < $deadline) {
            $this->pump();
            usleep(1000);
        }
    }

    public function __destruct()
    {
        if ($this->startedProcess && $this->result === null) {
            $this->cancel();
            $this->dispose();
        }
        $this->closeLog();
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
