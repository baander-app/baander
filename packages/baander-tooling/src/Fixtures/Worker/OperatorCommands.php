<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Development\{Docker, Environment, Runner};
use Baander\Tooling\Fixtures\Failure;
use Baander\Tooling\Process\{ProcessResult, ProcessRunner, ProcessSpec, SignalScope};
use RuntimeException;

final class OperatorCommands
{
    private readonly ProcessRunner $runner;
    private readonly SignalScope $signals;
    private readonly float $deadline;
    /** @var non-empty-list<string> */
    public readonly array $dockerPrefix;

    /** @param array<string,string> $environment */
    public function __construct(public readonly string $root, public readonly array $environment, int $timeout)
    {
        $this->runner = new ProcessRunner();
        $this->signals = new SignalScope();
        $this->deadline = self::now() + $timeout;
        $binary = Environment::executable('docker') ?? throw new RuntimeException('Docker is required');
        $ambient = ($environment['BAANDER_TEST_OPERATOR_IN_CONTAINER'] ?? '0') === '1'
            && ($environment['BAANDER_TEST_DOCKER_ENDPOINT'] ?? '') === '';
        $target = ['executable' => $binary];
        if (!$ambient) {
            $target['host'] = $environment['BAANDER_TEST_DOCKER_ENDPOINT'] ?? 'unix:///var/run/docker.sock';
        } elseif (($environment['DOCKER_CONTEXT'] ?? '') !== '') {
            $target['context'] = $environment['DOCKER_CONTEXT'];
        } elseif (($environment['DOCKER_HOST'] ?? '') !== '') {
            $target['host'] = $environment['DOCKER_HOST'];
        } else {
            $target['context'] = trim($this->run([$binary, 'context', 'show'])->stdout);
            if ($target['context'] === '') {
                throw new RuntimeException('Cannot determine the current Docker context');
            }
        }
        $this->dockerPrefix = new Docker(new Runner(), $target)->commandPrefix();
    }

    public static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
    public function close(): void
    {
        $this->signals->close();
    }

    /** @param non-empty-list<string> $arguments */
    public function run(
        array $arguments,
        bool $check = true,
        ?string $input = null,
        ?string $log = null,
        ?string $stdout = null,
        ?string $stderr = null,
        ?float $timeout = null,
        bool $cleanup = false,
        float $terminationGrace = 0.25,
    ): ProcessResult {
        if (!$cleanup) {
            $this->signals->dispatch();
            if ($this->signals->receivedSignal() !== null) {
                throw new Failure('Worker qualification interrupted', 128 + $this->signals->receivedSignal());
            }
            $remaining = $this->deadline - self::now();
            if ($remaining <= 0) {
                throw new Failure('Worker qualification timed out', 124);
            }
            $timeout = $timeout === null ? $remaining : min($remaining, $timeout);
        }
        $errorLog = $stderr ?? ($stdout === null ? null : $stdout . '.stderr');
        $result = $this->runner->run(new ProcessSpec(
            argv: $arguments,
            cwd: $this->root,
            environment: $this->environment,
            timeout: $timeout ?? 20,
            stdin: $input,
            logPath: $log ?? $stdout,
            processGroup: true,
            ignoreCancellation: $cleanup,
            stderrLogPath: $errorLog,
            terminationGrace: $terminationGrace,
        ));
        if ($result->timedOut || $result->cancelled || ($check && $result->exitCode !== 0)) {
            if ($log === null && $stdout === null) {
                $errors = $errorLog === null ? $result->stderr : (file_get_contents($errorLog) ?: '');
                $detail = "\n" . substr($result->stdout . $errors, -4000);
            } else {
                $detail = '; see ' . ($log ?? $stdout);
            }
            $status = match (true) {
                $result->timedOut => 124,
                $result->cancelled => 128 + ($result->signal ?? $this->signals->receivedSignal() ?? 15),
                default => $result->exitCode,
            };
            throw new Failure(
                'Command failed (' . $result->exitCode . '): ' . implode(' ', array_slice($arguments, 0, 4))
                . $detail,
                $status,
            );
        }
        return $result;
    }

    /** @param list<string> $arguments */
    public function docker(
        array $arguments,
        bool $check = true,
        ?string $input = null,
        ?string $log = null,
        ?string $stdout = null,
        ?string $stderr = null,
        ?float $timeout = null,
        bool $cleanup = false,
    ): ProcessResult {
        return $this->run(
            [...$this->dockerPrefix, ...$arguments],
            $check,
            $input,
            $log,
            $stdout,
            $stderr,
            $timeout,
            $cleanup,
        );
    }

    public function pause(float $seconds): void
    {
        $until = self::now() + $seconds;
        do {
            $this->signals->dispatch();
            usleep(10_000);
        } while (self::now() < $until && $this->signals->receivedSignal() === null);
        if ($this->signals->receivedSignal() !== null) {
            throw new Failure('Worker qualification interrupted', 128 + $this->signals->receivedSignal());
        }
        if (self::now() >= $this->deadline) {
            throw new Failure('Worker qualification timed out', 124);
        }
    }
}
