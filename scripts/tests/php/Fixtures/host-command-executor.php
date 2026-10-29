<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\RunningProcess;

final class HostCommandExecutor implements CommandExecutor
{
    /** @var list<array{arguments:non-empty-list<string>,env:array<string,string>,timeout:?float,interactive:bool}> */
    public array $calls = [];

    /** @param (\Closure(non-empty-list<string>):?ProcessResult)|null $callback */
    public function __construct(private readonly ?\Closure $callback = null) {}

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->calls[] = ['arguments' => $command->argv, 'env' => $command->environment,
            'timeout' => $command->timeout, 'interactive' => $command->passthrough];
        $result = $this->callback === null ? null : ($this->callback)($command->argv);
        return $policy->complete($result ?? new ProcessResult(0, null, false, false, "[main]\ndns=dnsmasq\n", '', 0), $command);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        throw new \LogicException('Unexpected asynchronous host command.');
    }

    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        throw new \LogicException('Unexpected asynchronous host wait.');
    }

    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        throw new \LogicException('Unexpected asynchronous host stop.');
    }
}
