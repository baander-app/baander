<?php

declare(strict_types=1);

namespace Baander\Tooling\Dependencies;

use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\RunningProcess;

final readonly class BudgetedExecutor implements CommandExecutor
{
    public function __construct(private CommandExecutor $executor, private float $timeout)
    {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('Dependency command budget must be positive finite seconds.');
        }
    }

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        return $this->executor->run($this->bounded($command), $policy);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        return $this->executor->start($this->bounded($command));
    }

    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        return $this->executor->wait($process, $policy);
    }

    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        return $this->executor->stop($process, $signal);
    }

    private function bounded(ProcessSpec $command): ProcessSpec
    {
        return $command->withExecution($command->environment, $command->cwd, min($command->timeout ?? $this->timeout, $this->timeout), $command->ignoreCancellation);
    }
}
