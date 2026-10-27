<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Http;

use Baander\Tooling\Development\{Cli, Docker, Runner};
use Baander\Tooling\Process\{ProcessResult, ProcessRunner, ProcessSpec, SignalScope};
use RuntimeException;

final class Drill extends Runner
{
    private readonly float $deadline;
    private readonly SignalScope $signals;
    private readonly ProcessRunner $processes;
    private ?Docker $target = null;
    public bool $cleanup = false;

    public function __construct(public readonly string $root, int $timeout = 180)
    {
        $this->deadline = hrtime(true) / 1e9 + $timeout;
        $this->signals = new SignalScope();
        $this->processes = new ProcessRunner();
    }

    public function connect(): void
    {
        $context = getenv('BAANDER_TEST_DOCKER_CONTEXT') ?: 'default';
        $this->target = new Docker($this, Cli::dockerTarget($this, $context));
    }

    public function close(): void
    {
        $this->signals->close();
    }

    /**
     * @param non-empty-list<string> $arguments
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
        if (!$this->cleanup) {
            $this->signals->dispatch();
            if ($this->signals->receivedSignal() !== null) {
                throw new DrillFailure('HTTP fixture drill interrupted', 128 + $this->signals->receivedSignal());
            }
            $remaining = $this->deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new DrillFailure('HTTP fixture drill exceeded its overall timeout', 124);
            }
            $timeout = $timeout === null ? $remaining : min($remaining, $timeout);
        }
        $result = $this->processes->run(new ProcessSpec(
            argv: $arguments,
            cwd: $cwd ?? $this->root,
            environment: $env ?? getenv(),
            timeout: $timeout,
            stdin: $input,
            logPath: $logPath,
            passthrough: $interactive,
            processGroup: !$interactive,
            ignoreCancellation: $this->cleanup,
        ));
        if ($result->cancelled || $result->timedOut || ($check && $result->exitCode !== 0)) {
            $status = match (true) {
                $result->timedOut => 124,
                $result->cancelled => 128 + ($this->signals->receivedSignal() ?? 15),
                default => $result->exitCode,
            };
            throw new DrillFailure(
                'HTTP fixture command failed (' . $result->exitCode . '): ' . implode(' ', array_slice($arguments, 0, 3))
                    . "\n" . ($logPath ?? $result->stdout . $result->stderr),
                $status,
            );
        }
        return $result;
    }

    /** @param list<string> $arguments */
    public function docker(array $arguments, ?float $timeout = 20, bool $check = true, ?string $log = null): ProcessResult
    {
        return ($this->target ?? throw new RuntimeException('HTTP fixture Docker target is not connected'))->run(
            $arguments,
            timeout: $timeout,
            check: $check,
            logPath: $log,
        );
    }
}
