<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessSpec;

class Runner
{
    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function run(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        bool $check = true,
        ?string $logPath = null,
        bool $interactive = false,
    ): ProcessResult {
        $name = basename($arguments[0]);
        try {
            $result = new ProcessRunner()->run(new ProcessSpec(
                argv: $arguments,
                cwd: $cwd,
                environment: array_replace(Environment::clean(), $env ?? []),
                timeout: $timeout,
                stdin: $input,
                logPath: $logPath,
                passthrough: $interactive,
                processGroup: !$interactive,
            ));
        } catch (\Throwable $error) {
            throw new WorkflowError($name . ' did not complete; inspect the recorded operation before retrying.', previous: $error);
        }
        if ($result->timedOut || $result->cancelled) {
            throw new WorkflowError($name . ' did not complete; inspect the recorded operation before retrying.');
        }
        if ($check && $result->exitCode !== 0) {
            throw new WorkflowError($name . ' exited with status ' . $result->exitCode . '.' . ($logPath === null ? '' : ' See ' . $logPath . '.'));
        }
        return $result;
    }
}
