<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy};
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\RunningProcess;

final class Commands
{
    private int $sequence = 0;
    /** @var list<RunningProcess> */
    private array $pending = [];

    /** @param array<string,string> $environment */
    public function __construct(private readonly string $cwd, private readonly string $logs, private readonly CommandExecutor $executor, private readonly array $environment) {}

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
        $process = $this->executor->start($this->spec($argv, timeout: $timeout));
        $this->pending[] = $process;
        return $process;
    }

    public function wait(RunningProcess $process): ProcessResult
    {
        $result = $this->executor->wait($process, new CompletionPolicy(null));
        $this->pending = $this->pending
            |> (static fn(array $pending): array => array_filter(
                $pending,
                static fn(RunningProcess $item): bool => $item !== $process,
            ))
            |> array_values(...);
        return $result;
    }

    public function pause(int $microseconds): void
    {
        $failure = null;
        \Swoole\Process::signal(
            SIGTERM,
            static function () use (&$failure): void {
                $failure ??= new \RuntimeException('Qualification cancelled by SIGTERM.');
            },
        );
        \Swoole\Process::signal(
            SIGINT,
            static function () use (&$failure): void {
                $failure ??= new \RuntimeException('Qualification cancelled by SIGINT.');
            },
        );
        $deadline = Support::now() + $microseconds / 1000000;
        $timer = \Swoole\Timer::tick(5, function () use (&$failure): void {
            try {
                foreach ($this->pending as $process) {
                    $process->pump();
                }
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        });
        try {
            do {
                \Swoole\Event::dispatch();
                if ($failure !== null) {
                    throw $failure;
                }
            } while (Support::now() < $deadline);
        } finally {
            \Swoole\Timer::clear($timer);
            \Swoole\Process::signal(SIGTERM, null);
            \Swoole\Process::signal(SIGINT, null);
            if (\Swoole\Timer::stats()['num'] === 0) {
                \Swoole\Event::wait();
            }
        }
    }
}
