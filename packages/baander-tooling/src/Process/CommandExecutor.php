<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

interface CommandExecutor
{
    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult;

    public function start(ProcessSpec $command): RunningProcess;

    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult;

    public function stop(RunningProcess $process, int $signal = 15): ProcessResult;
}
