<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

final readonly class ExecutionContext
{
    /** @param array<string,string> $environment */
    public function __construct(
        public array $environment,
        public ?string $cwd = null,
        public ?float $deadline = null,
        public bool $ignoreCancellation = false,
    ) {
        if ($deadline !== null && !is_finite($deadline)) {
            throw new \InvalidArgumentException('Execution deadline must be finite.');
        }
    }

    /** @param array<string,string> $environment */
    public static function bounded(float $seconds, array $environment, ?string $cwd = null, bool $ignoreCancellation = false): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('Execution budget must be positive finite seconds.');
        }
        return new self($environment, $cwd, hrtime(true) / 1_000_000_000 + $seconds, $ignoreCancellation);
    }

    public function remaining(): ?float
    {
        if ($this->deadline === null) {
            return null;
        }
        $remaining = $this->deadline - hrtime(true) / 1_000_000_000;
        if ($remaining <= 0) {
            throw new CommandFailure('Execution budget expired.', 124);
        }
        return $remaining;
    }

    public function command(ProcessSpec $command): ProcessSpec
    {
        $remaining = $this->remaining();
        $timeout = $command->timeout;
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) {
            throw new \InvalidArgumentException('Command timeout must be positive finite seconds or an owning-scope lifetime.');
        }
        if ($remaining !== null) {
            $timeout = $timeout === null ? $remaining : min($timeout, $remaining);
        }
        return $command->withExecution(
            environment: $command->replaceEnvironment ? $command->environment : array_replace($this->environment, $command->environment),
            cwd: $command->cwd ?? $this->cwd,
            timeout: $timeout,
            ignoreCancellation: $this->ignoreCancellation || $command->ignoreCancellation,
        );
    }
}
