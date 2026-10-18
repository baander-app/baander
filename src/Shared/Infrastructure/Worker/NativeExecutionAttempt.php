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
    public string $stderr = '';
    public ?int $exitCode = null;
    public ?string $error = null;
    public ?float $stopDeadline = null;
    public ?int $pendingSignal = null;
    public ?float $signalDeadline = null;

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
    }

    /** @return array{token:string, attempt:int, state:string, stdout:string, stderr:string, exitCode:?int, error:?string} */
    public function snapshot(): array
    {
        return ['token' => $this->token, 'attempt' => $this->attempt, 'state' => $this->state,
            'stdout' => $this->stdout, 'stderr' => $this->stderr, 'exitCode' => $this->exitCode, 'error' => $this->error];
    }
}
