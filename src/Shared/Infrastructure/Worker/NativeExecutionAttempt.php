<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Launcher-owned state, never serialized with its process resources or PID. */
#[Exclude]
final class NativeExecutionAttempt
{
    public string $state = 'running';
    public string $stdout = '';
    public readonly OutputTail $stderr;
    /** Standard output passed its bound; later output is drained and discarded. */
    public bool $outputExceeded = false;
    public ?int $exitCode = null;
    public ?string $error = null;
    public ?float $stopDeadline = null;
    public ?int $pendingSignal = null;
    public ?float $signalDeadline = null;
    /** CPU time the command and its reaped descendants have consumed, user and system together. */
    public float $cpuSeconds = 0.0;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    public function __construct(
        public readonly string $token,
        public readonly string $session,
        public readonly int $attempt,
        public readonly string $fingerprint,
        public readonly int $pid,
        public $process,
        public array $pipes,
        public readonly float $deadline,
    ) {
        $this->stderr = new OutputTail(NativeExecutionProtocol::ERROR_TAIL_BYTES);
    }

    /**
     * Keeps a chunk the command wrote. Standard output past its bound blanks what was kept and
     * returns true once, so the caller stops the command; the attempt then fails.
     */
    public function collect(int $stream, string $chunk): bool
    {
        if ($stream === 2) {
            $this->stderr->append($chunk);

            return false;
        }
        if ($this->outputExceeded) {
            return false;
        }
        if (strlen($this->stdout) + strlen($chunk) <= NativeExecutionProtocol::OUTPUT_BYTES) {
            $this->stdout .= $chunk;

            return false;
        }
        $this->outputExceeded = true;
        $this->stdout = '';
        $this->error ??= 'Native output byte limit exceeded.';

        return true;
    }

    /**
     * Records the command's exit. Callers judge an attempt by its exit code alone, so an attempt
     * with an error never reads as a success, and none returns output it may have cut short.
     */
    public function exit(int $code): void
    {
        $this->exitCode = $this->error !== null && $code === 0 ? 1 : $code;
        if ($this->error !== null) {
            $this->stdout = '';
        }
    }

    /** @return array{token:string, attempt:int, state:string, stdout:string, stderr:string, exitCode:?int, error:?string, cpuSeconds:float} */
    public function snapshot(): array
    {
        return ['token' => $this->token, 'attempt' => $this->attempt, 'state' => $this->state,
            'stdout' => $this->stdout, 'stderr' => $this->stderr->contents(), 'exitCode' => $this->exitCode, 'error' => $this->error,
            'cpuSeconds' => $this->cpuSeconds];
    }
}
