<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures;

use Baander\Tooling\Configuration\{ApplicationLayout, ToolingConfiguration};
use Baander\Tooling\Docker\{Client, ResolvedImage, Target, TargetResolver};
use Baander\Tooling\Process\{CommandFailure, CompletionPolicy, EngineExecutor, ExecutionContext, ProcessResult, ProcessSpec, RunningProcess};
use Baander\Tooling\Resources\ResourceLedger;
use RuntimeException;
use Throwable;

final class Runtime
{
    public readonly EngineExecutor $executor;
    public readonly ToolingConfiguration $configuration;
    private ?EngineExecutor $cleanupExecutor = null;
    private ?Target $target = null;
    private ?ApplicationLayout $layout = null;
    /** @var array<string,ResolvedImage> */
    private array $images = [];
    /** @var \WeakMap<RunningProcess,EngineExecutor> */
    private \WeakMap $owners;
    public bool $cleaning = false;
    /** @var array<string,string> */
    public readonly array $environment;
    /** @param array<string,string>|null $environment */
    public function __construct(public readonly string $root, ?float $seconds = null, ?array $environment = null, ?ToolingConfiguration $configuration = null, string $budget = 'fixture')
    {
        $this->environment = $environment ?? getenv();
        $this->configuration = $configuration ?? ToolingConfiguration::forRepository($root, $this->environment);
        $this->executor = new EngineExecutor(ExecutionContext::bounded($seconds ?? $this->configuration->budgets->seconds($budget), $this->environment, $root));
        $this->owners = new \WeakMap();
    }
    public static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
    public function cleanupExecutor(): EngineExecutor
    {
        return $this->cleanupExecutor ??= new EngineExecutor(ExecutionContext::bounded($this->configuration->budgets->seconds('cleanup'), $this->environment, $this->root, ignoreCancellation: true));
    }
    private function commands(): EngineExecutor
    {
        return $this->cleaning ? $this->cleanupExecutor() : $this->executor;
    }
    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function run(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, bool $check = true, ?string $logPath = null, bool $interactive = false, ?string $stderrLogPath = null, float $terminationGrace = .25): ProcessResult
    {
        return $this->wait($this->start($arguments, $cwd, $env, $input, $timeout, $logPath, $interactive, $stderrLogPath, $terminationGrace), $check);
    }
    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function start(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, ?string $logPath = null, bool $interactive = false, ?string $stderrLogPath = null, float $terminationGrace = .25): RunningProcess
    {
        $executor = $this->commands();
        $process = $executor->start(new ProcessSpec($arguments, cwd: $cwd, environment: $env ?? $this->environment, timeout: $timeout, stdin: $input, logPath: $logPath, passthrough: $interactive, processGroup: !$interactive, stderrLogPath: $stderrLogPath, terminationGrace: $terminationGrace, replaceEnvironment: true));
        $this->owners[$process] = $executor;
        return $process;
    }
    public function wait(RunningProcess $process, bool $check = true): ProcessResult
    {
        try {
            return ($this->owners[$process] ?? $this->executor)->wait($process, new CompletionPolicy($check ? [0] : null));
        } finally {
            unset($this->owners[$process]);
        }
    }
    public function checkpoint(): void
    {
        $this->commands()->checkpoint();
    }
    public function pause(float $seconds): void
    {
        $until = self::now() + $seconds;
        do {
            $this->checkpoint();
            $this->executor->pump();
            $this->cleanupExecutor?->pump();
            usleep((int) min(10_000, max(0, ($until - self::now()) * 1_000_000)));
        } while (self::now() < $until);
    }
    public function alive(RunningProcess $process): bool
    {
        $this->executor->pump();
        $this->cleanupExecutor?->pump();
        return $process->result() === null;
    }
    /** @param callable():bool $condition */
    public function await(callable $condition, float $seconds, float $interval = .1, string $message = 'Fixture did not become ready'): void
    {
        $until = self::now() + $seconds;
        do {
            if ($condition()) {
                return;
            } $this->pause($interval);
        } while (self::now() < $until);
        throw new RuntimeException($message);
    }
    public function stop(RunningProcess $process, bool $check = false): ProcessResult
    {
        try {
            $result = ($this->owners[$process] ?? $this->executor)->stop($process);
            if ($check && $result->exitCode !== 0) {
                throw new CommandFailure('Stopped fixture command returned a nonzero status.', $result->exitCode, $result);
            }
            return $result;
        } finally {
            unset($this->owners[$process]);
        }
    }
    public function close(): void
    {
        try {
            $this->executor->close();
        } finally {
            $this->cleanupExecutor?->close();
            $this->owners = new \WeakMap();
        }
    }
    public function docker(): Client
    {
        if ($this->target === null) {
            $directory = self::artifacts($this->root, 'fixture-transport', $this->environment) . '/transport-' . bin2hex(random_bytes(8));
            $this->target = new TargetResolver($this->commands(), $this->environment)->resolve($this->configuration->docker, $directory);
        }
        return new Client($this->commands(), $this->target, $this->environment);
    }
    public function image(string $role): ResolvedImage
    {
        return $this->images[$role] ??= $this->docker()->resolveImage($this->configuration->images->role($role), pull: false);
    }
    public function layout(): ApplicationLayout
    {
        if ($this->layout === null) {
            $image = $this->image('application');
            $inspection = json_decode($this->docker()->run(['image', 'inspect', $image->id])->stdout, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($inspection) || !array_is_list($inspection) || count($inspection) !== 1 || !is_array($inspection[0])) {
                throw new RuntimeException('Application image inspection must contain one image.');
            }
            $this->layout = ApplicationLayout::fromImage($inspection[0]);
        }
        return $this->layout;
    }
    public function resources(string $artifacts): ResourceLedger
    {
        return new ResourceLedger($artifacts . '/resources.json', $this->docker(), $this->environment);
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
            if ($error instanceof CommandFailure) {
                $status = $error->getCode();
            } elseif ($error instanceof Failure) {
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

    /** @param array<string,string>|null $environment */
    public static function artifacts(string $root, string $name, ?array $environment = null): string
    {
        $environment ??= getenv();
        $path = ($environment['BAANDER_TEST_ARTIFACTS'] ?? '') ?: $root . '/var/test-runs/' . $name . '-' . time() . '-' . getmypid();
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new RuntimeException('Cannot create fixture artifacts');
        }
        if (!chmod($path, 0700)) {
            throw new RuntimeException('Cannot protect fixture artifacts');
        }
        return $path;
    }
}
