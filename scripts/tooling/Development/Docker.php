<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Process\ProcessResult;

final class Docker
{
    /** @param array<string,mixed> $configuration */
    public function __construct(public readonly Runner $runner, public readonly array $configuration) {}

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function run(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null,
        ?float $timeout = 120, bool $check = true, ?string $logPath = null, bool $interactive = false): ProcessResult
    {
        return $this->runner->run([$this->configuration['executable'], '--context', $this->configuration['context'], ...$arguments],
            cwd: $cwd, env: $env, input: $input, timeout: $timeout, check: $check, logPath: $logPath, interactive: $interactive);
    }

    /** @return array<string,mixed> */
    public function validate(): array
    {
        try {
            $information = json_decode($this->run(['info', '--format', '{{json .}}'])->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new WorkflowError('Cannot verify Docker daemon inventory.', previous: $error);
        }
        if (!is_array($information) || ($information['ID'] ?? null) !== ($this->configuration['daemon_id'] ?? null)) {
            throw new WorkflowError('Docker daemon identity changed; inspect instance configuration before continuing.');
        }
        return $information;
    }

    /** @param array<string,mixed> $instance
     * @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function compose(array $instance, array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null,
        ?float $timeout = 120, bool $check = true, ?string $logPath = null, bool $interactive = false): ProcessResult
    {
        return $this->run(['compose', '--project-name', $instance['project'], '--file', $instance['compose_file'], ...$arguments],
            cwd: $cwd, env: $env, input: $input, timeout: $timeout, check: $check, logPath: $logPath, interactive: $interactive);
    }
}
