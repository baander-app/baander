<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\{EngineExecutor, CompletionPolicy};
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\RunningProcess;

final class Commands
{
    private int $sequence = 0;
    /** @param array<string,string> $environment */
    public function __construct(private readonly string $cwd, private readonly string $logs, private readonly EngineExecutor $executor, private readonly array $environment) {}

    /** @param non-empty-list<string> $argv */
    private function spec(array $argv, ?string $cwd = null, ?float $timeout = null): ProcessSpec
    {
        return new ProcessSpec(
            argv: $argv,
            cwd: $cwd ?? $this->cwd,
            environment: $this->environment,
            replaceEnvironment: true,
            timeout: $timeout,
            logPath: $this->logs . '/' . sprintf('%03d', ++$this->sequence) . '-' . basename($argv[0]) . '.log',
            processGroup: true,
        );
    }

    /** @param non-empty-list<string> $argv */
    public function run(array $argv, ?string $cwd = null, int $expected = 0): string
    {
        $spec = $this->spec($argv, $cwd);
        $this->executor->run($spec, new CompletionPolicy([$expected]));
        return (string) file_get_contents((string) $spec->logPath);
    }

    /** @param non-empty-list<string> $argv */
    #[\NoDiscard]
    public function start(array $argv, ?float $timeout = null): RunningProcess
    {
        return $this->executor->start($this->spec($argv, timeout: $timeout));
    }

    public function wait(RunningProcess $process): ProcessResult
    {
        return $this->executor->wait($process, new CompletionPolicy(null));
    }

    public function pause(int $microseconds): void
    {
        $deadline = Support::now() + $microseconds / 1000000;
        do {
            $this->executor->checkpoint();
            $this->executor->pump();
        } while (Support::now() < $deadline);
    }
}
