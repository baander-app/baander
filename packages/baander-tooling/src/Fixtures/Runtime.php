<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures;

use Baander\Tooling\Development\{Docker, Environment, Runner};
use Baander\Tooling\Process\{ProcessResult, ProcessRunner, ProcessSpec, RunningProcess, SignalScope};
use RuntimeException;
use Throwable;

final class Runtime extends Runner
{
    private readonly ProcessRunner $processes;
    private readonly SignalScope $signals;
    private readonly float $deadline;
    public bool $cleaning = false;
    /** @var array<string,string> */
    public array $environment;
    /** @var list<RunningProcess> */
    private array $children = [];

    /** @param array<string,string>|null $environment */
    public function __construct(public readonly string $root, float $seconds = 600, ?array $environment = null)
    {
        $this->environment = $environment ?? getenv();
        $this->deadline = self::now() + $seconds;
        $this->processes = new ProcessRunner();
        $this->signals = new SignalScope();
    }

    public static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

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
        ?string $stderrLogPath = null,
        float $terminationGrace = .25,
    ): ProcessResult {
        return $this->wait(
            $this->start(
                $arguments,
                $cwd,
                $env,
                $input,
                $timeout,
                $logPath,
                $interactive,
                $stderrLogPath,
                $terminationGrace,
            ),
            $check,
        );
    }

    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function start(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        ?string $logPath = null,
        bool $interactive = false,
        ?string $stderrLogPath = null,
        float $terminationGrace = .25,
    ): RunningProcess {
        $this->checkpoint();
        if (!$this->cleaning) {
            $remaining = $this->deadline - self::now();
            $timeout = $timeout === null ? $remaining : min($timeout, $remaining);
        }
        $process = $this->processes->start(new ProcessSpec(
            argv: $arguments,
            cwd: $cwd ?? $this->root,
            environment: $env ?? $this->environment,
            timeout: $timeout,
            stdin: $input,
            logPath: $logPath,
            passthrough: $interactive,
            processGroup: !$interactive,
            ignoreCancellation: $this->cleaning,
            stderrLogPath: $stderrLogPath,
            terminationGrace: $terminationGrace,
        ));
        $this->children[] = $process;
        return $process;
    }

    public function wait(RunningProcess $process, bool $check = true): ProcessResult
    {
        $result = $this->processes->wait($process);
        if ($check && ($result->exitCode !== 0 || $result->timedOut || $result->cancelled)) {
            throw new Failure(
                'Fixture command failed (exit ' . $result->exitCode . ')'
                . ($result->timedOut ? ' after timeout' : '') . ($result->cancelled ? ' after interruption' : '')
                . ': ' . substr($result->stdout . $result->stderr, -4000),
                $result->timedOut ? 124 : ($result->cancelled ? 128 + ($result->signal ?? 15) : $result->exitCode),
            );
        }
        return $result;
    }

    public function checkpoint(): void
    {
        $this->signals->dispatch();
        if (!$this->cleaning && $this->signals->receivedSignal() !== null) {
            throw new Failure('Fixture interrupted', 128 + $this->signals->receivedSignal());
        }
        if (!$this->cleaning && self::now() >= $this->deadline) {
            throw new Failure('Fixture overall timeout expired', 124);
        }
    }

    public function pause(float $seconds): void
    {
        $until = self::now() + $seconds;
        do {
            $this->checkpoint();
            foreach ($this->children as $child) {
                $child->pump();
            }
            usleep((int) min(10_000, max(0, ($until - self::now()) * 1_000_000)));
        } while (self::now() < $until);
    }

    public function alive(RunningProcess $process): bool
    {
        $process->pump();
        return $process->result() === null;
    }

    /** @param callable():bool $condition */
    public function await(
        callable $condition,
        float $seconds,
        float $interval = .1,
        string $message = 'Fixture did not become ready',
    ): void {
        $until = self::now() + $seconds;
        do {
            if ($condition()) {
                return;
            }
            $this->pause($interval);
        } while (self::now() < $until);
        throw new RuntimeException($message);
    }

    public function stop(RunningProcess $process, bool $check = false): ProcessResult
    {
        $process->cancel();
        return $this->wait($process, $check);
    }

    public function close(): void
    {
        $this->cleaning = true;
        foreach ($this->children as $child) {
            if ($child->result() === null) {
                try {
                    $this->stop($child);
                } catch (Throwable $error) {
                    fwrite(STDERR, $error->getMessage() . "\n");
                }
            }
        }
        $this->signals->close();
    }

    public function docker(bool $operator = false): Docker
    {
        $binary = Environment::executable('docker') ?? throw new RuntimeException('Install Docker first');
        $endpoint = $this->environment['BAANDER_TEST_DOCKER_ENDPOINT'] ?? '';
        if ($operator && (($this->environment['BAANDER_TEST_OPERATOR_IN_CONTAINER'] ?? '0') !== '1' || $endpoint !== '')) {
            $endpoint = $endpoint !== '' ? $endpoint : 'unix:///var/run/docker.sock';
        }
        if ($endpoint !== '') {
            return new Docker($this, ['executable' => $binary, 'host' => $endpoint]);
        }
        $context = $this->environment['DOCKER_CONTEXT'] ?? '';
        $host = $this->environment['DOCKER_HOST'] ?? '';
        if ($context === '' && $host !== '') {
            return new Docker($this, ['executable' => $binary, 'host' => $host]);
        }
        if ($context === '') {
            $context = $this->run([$binary, 'context', 'show'])->stdout
                |> trim(...);
        }
        if ($context === '') {
            throw new RuntimeException('Cannot determine Docker context');
        }
        return new Docker($this, ['executable' => $binary, 'context' => $context]);
    }

    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function failure(Throwable $error): string
    {
        $messages = [];
        do {
            array_unshift($messages, $error->getMessage());
            $error = $error->getPrevious();
        } while ($error !== null);
        return implode("\n", $messages);
    }

    public static function status(Throwable $error): int
    {
        $status = 1;
        do {
            if ($error instanceof Failure) {
                $status = $error->status;
            } elseif ($error instanceof \InvalidArgumentException) {
                $status = 2;
            }
            $error = $error->getPrevious();
        } while ($error !== null);
        return $status;
    }

    /** @return array<mixed> */
    public static function json(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read fixture artifact: ' . $path);
        }
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Expected JSON object or array: ' . $path);
        }
        return $data;
    }

    public static function writeJson(string $path, mixed $value): void
    {
        if (file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new RuntimeException('Cannot write fixture artifact: ' . $path);
        }
    }

    public static function artifacts(string $root, string $name): string
    {
        $path = getenv('BAANDER_TEST_ARTIFACTS') ?: $root . '/var/test-runs/' . $name . '-' . time() . '-' . getmypid();
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new RuntimeException('Cannot create fixture artifacts');
        }
        if (!chmod($path, 0700)) {
            throw new RuntimeException('Cannot protect fixture artifacts');
        }
        return $path;
    }
}
