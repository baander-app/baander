<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Fresh-exec CLI child with inherited output and nonblocking shutdown polling.
 *
 * This owns only the direct child. Deployment containment must own descendants
 * and supervisor death before this primitive is used by a deployment command.
 */
#[Exclude]
final class WorkerChildProcess
{
    /** @var resource|null */
    private mixed $process;
    private ?float $killAt = null;
    private bool $killSent = false;
    private ?int $exitCode = null;
    private ?int $terminationSignal = null;
    private ?float $lastTime = null;

    /** @param resource $process */
    private function __construct(mixed $process, private readonly int $pid)
    {
        $this->process = $process;
    }

    /**
     * Output streams are borrowed, never buffered or closed by this object.
     * Slow log destinations may backpressure the child, not the supervisor loop.
     *
     * @param list<string> $command Argument list; an empty list is rejected
     * @param resource $stdout Writable stream; normally STDOUT
     * @param resource $stderr Writable stream; normally STDERR
     * @param array<string, string>|null $environment Complete environment, or null to inherit
     */
    public static function start(array $command, string $directory, mixed $stdout, mixed $stderr, ?array $environment = null): self
    {
        if ($command === [] || $command[0] === '') {
            throw new \InvalidArgumentException('Worker command must be a non-empty argument list.');
        }
        foreach ($command as $argument) {
            if (str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('Worker arguments must be strings without NUL bytes.');
            }
        }
        if (!is_dir($directory) || !str_starts_with($directory, '/')) {
            throw new \InvalidArgumentException('Worker working directory must be an existing absolute directory.');
        }
        foreach ([$stdout, $stderr] as $stream) {
            if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
                throw new \InvalidArgumentException('Worker output requires open stream resources.');
            }
        }

        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stderr], $pipes, $directory, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start worker child.');
        }
        $status = proc_get_status($process);
        $child = new self($process, $status['pid']);
        $child->recordStatus($status);

        return $child;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /** Poll using hrtime(true) / 1e9; returns true until the child is reaped. */
    public function poll(float $now): bool
    {
        $this->checkTime($now);
        if ($this->process === null) {
            return false;
        }
        if (!$this->recordStatus(proc_get_status($this->process))) {
            return false;
        }
        if ($this->killAt !== null && $now >= $this->killAt && !$this->killSent) {
            $this->signal(SIGKILL);
            $this->killSent = true;
        }

        return true;
    }

    /** Repeated requests never extend the original drain deadline. */
    public function requestStop(float $now, float $graceSeconds): void
    {
        if (!is_finite($graceSeconds) || $graceSeconds < 0 || !is_finite($now + $graceSeconds)) {
            throw new \InvalidArgumentException('Worker drain grace must be finite and non-negative.');
        }
        if (!$this->poll($now) || $this->killAt !== null) {
            return;
        }
        $this->killAt = $now + $graceSeconds;
        $this->signal(SIGTERM);
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function terminationSignal(): ?int
    {
        return $this->terminationSignal;
    }

    private function checkTime(float $now): void
    {
        if (!is_finite($now) || $now < 0 || ($this->lastTime !== null && $now < $this->lastTime)) {
            throw new \InvalidArgumentException('Worker lifecycle requires a finite, non-decreasing monotonic time.');
        }
        $this->lastTime = $now;
    }

    private function signal(int $signal): void
    {
        if ($this->process !== null && !proc_terminate($this->process, $signal) && $this->recordStatus(proc_get_status($this->process))) {
            throw new \RuntimeException('Could not signal worker child.');
        }
    }

    /** @param array{running: bool, signaled: bool, termsig: int, exitcode: int} $status */
    private function recordStatus(array $status): bool
    {
        if ($status['running']) {
            return true;
        }
        // PHP preserves normal exit codes, but a second status read can lose
        // signal information. Consume every stopped snapshot exactly once.
        $this->terminationSignal = $status['signaled'] ? $status['termsig'] : null;
        $this->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
        if ($this->process !== null) {
            $closedCode = proc_close($this->process);
            $this->exitCode ??= $closedCode >= 0 ? $closedCode : null;
            $this->process = null;
        }

        return false;
    }

    public function __destruct()
    {
        // Last-resort direct-child cleanup, not a substitute for deployment containment.
        if ($this->process !== null) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, SIGKILL);
            }
            proc_close($this->process);
        }
    }
}
