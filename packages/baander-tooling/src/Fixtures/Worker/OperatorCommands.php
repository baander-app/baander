<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Process\{CompletionPolicy, ProcessResult, ProcessSpec};

final class OperatorCommands
{
    public readonly Runtime $runtime;
    private ?string $targetFile = null;

    /** @param array<string,string> $environment */
    public function __construct(public readonly string $root, public readonly array $environment, ?int $timeout = null)
    {
        $this->runtime = new Runtime($root, $timeout, $environment, budget: 'workerOperator');
    }

    public function exportTarget(string $path): void
    {
        AtomicFile::writeJson($path, $this->client()->target->toArray());
        $this->targetFile = $path;
    }

    public static function now(): float
    {
        return Runtime::now();
    }

    public function close(): void
    {
        $this->runtime->close();
    }

    public function client(bool $cleanup = false): Client
    {
        $client = $this->runtime->docker();
        return $cleanup ? new Client($this->runtime->cleanupExecutor(), $client->target, $this->environment) : $client;
    }

    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $environment
     */
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
        ?array $environment = null,
    ): ProcessResult {
        $executor = $cleanup ? $this->runtime->cleanupExecutor() : $this->runtime->executor;
        return $executor->run(new ProcessSpec(
            argv: $arguments,
            cwd: $this->root,
            environment: array_replace($environment ?? $this->environment, $this->targetFile === null ? [] : ['BAANDER_TOOLING_TARGET_FILE' => $this->targetFile]),
            timeout: $timeout,
            stdin: $input,
            logPath: $log ?? $stdout,
            processGroup: true,
            stderrLogPath: $stderr ?? ($stdout === null ? null : $stdout . '.stderr'),
            terminationGrace: $terminationGrace,
            replaceEnvironment: true,
        ), new CompletionPolicy($check ? [0] : null));
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
        return $this->client($cleanup)->run(
            $arguments,
            cwd: $this->root,
            input: $input,
            timeout: $timeout,
            check: $check,
            logPath: $log ?? $stdout,
            stderrLogPath: $stderr ?? ($stdout === null ? null : $stdout . '.stderr'),
        );
    }

    public function pause(float $seconds): void
    {
        $this->runtime->pause($seconds);
    }
}
