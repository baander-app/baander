<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\ProcessResult;

final class WorkerCommandExecutor extends DevelopmentCommandExecutor
{
    /** @var list<array{arguments:non-empty-list<string>,input:?string,env:?array<string,string>,timeout:?float,check:bool}> */
    public array $calls = [];
    /** @var array<string,non-empty-list<string>> */
    private array $created = [];
    /** @var array<string,ProcessResult> */
    private array $completed = [];
    private readonly DevDataRunner $commands;

    /** @param \Closure(non-empty-list<string>,?string,?array<string,string>):(string|ProcessResult) $callback */
    public function __construct(\Closure $callback)
    {
        $this->commands = new DevDataRunner($callback);
    }

    public function execute(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        bool $check = true,
        ?string $logPath = null,
        bool $interactive = false,
    ): ProcessResult {
        $this->calls[] = compact('arguments', 'input', 'env', 'timeout', 'check');
        if (($arguments[3] ?? null) === 'create') {
            $name = $arguments[array_search('--name', $arguments, true) + 1];
            $this->created[$name] = $arguments;
            $result = parent::execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
            $this->created[trim($result->stdout)] = $arguments;
            return $result;
        }
        if (($arguments[3] ?? null) === 'start') {
            $name = $arguments[array_key_last($arguments)];
            return $this->completed[$name] = $this->commands->execute($this->created[$name], $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
        }
        if (($arguments[3] ?? null) === 'inspect' && in_array('{{.State.ExitCode}}', $arguments, true)) {
            return DevDataRunner::result((string) $this->completed[$arguments[array_key_last($arguments)]]->exitCode);
        }
        return $this->commands->execute($arguments, $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
    }
}
