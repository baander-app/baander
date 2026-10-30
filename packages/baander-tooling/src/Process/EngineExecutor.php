<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

final class EngineExecutor implements CommandExecutor
{
    private readonly ProcessRunner $runner;
    private readonly SignalScope $signals;
    /** @var \WeakMap<RunningProcess,ProcessSpec> */
    private \WeakMap $commands;

    public function __construct(public readonly ExecutionContext $context, ?ProcessRunner $runner = null)
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->signals = new SignalScope();
        $this->commands = new \WeakMap();
    }

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        return $this->wait($this->start($command), $policy);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        $this->checkpoint();
        $effective = $this->context->command($command);
        $process = $this->runner->start($effective);
        $this->commands[$process] = $effective;
        return $process;
    }

    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        try {
            return $policy->complete($this->runner->wait($process), $this->commands[$process] ?? null);
        } finally {
            unset($this->commands[$process]);
        }
    }

    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        $process->cancel($signal);
        try {
            return $this->runner->wait($process);
        } finally {
            unset($this->commands[$process]);
        }
    }

    public function checkpoint(): void
    {
        $this->signals->dispatch();
        if (!$this->context->ignoreCancellation && ($signal = $this->signals->receivedSignal()) !== null) {
            throw new CommandFailure('Execution interrupted.', 128 + $signal);
        }
        $this->context->remaining();
    }

    public function pump(): void
    {
        $this->runner->pump();
    }

    public function close(): void
    {
        try {
            $this->runner->close();
        } finally {
            $this->commands = new \WeakMap();
            $this->signals->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
