<?php

declare(strict_types=1);

namespace Baander\Tooling\Docker;

use Baander\Tooling\Configuration\ImageSelection;
use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\RunningProcess;

final readonly class Client
{
    /** @param array<string,string> $environment */
    public function __construct(public CommandExecutor $executor, public Target $target, private array $environment) {}

    /** @param array<string,string> $environment
     * @return array<string,string>
     */
    public static function transportEnvironment(array $environment): array
    {
        foreach (['DOCKER_CONTEXT', 'DOCKER_HOST', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'] as $name) {
            unset($environment[$name]);
        }
        return $environment;
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function command(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, ?string $logPath = null, bool $interactive = false): ProcessSpec
    {
        $this->target->assertSnapshot();
        return new ProcessSpec(
            [$this->target->executable, '--host', $this->target->endpoint, ...$this->target->transportArguments, ...$arguments],
            cwd: $cwd,
            environment: self::transportEnvironment(array_replace($this->environment, $env ?? [])),
            timeout: $timeout,
            stdin: $input,
            logPath: $logPath,
            passthrough: $interactive,
            processGroup: !$interactive,
            replaceEnvironment: true,
        );
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function run(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, bool $check = true, ?string $logPath = null, bool $interactive = false): ProcessResult
    {
        return $this->executor->run($this->command($arguments, $cwd, $env, $input, $timeout, $logPath, $interactive), new CompletionPolicy($check ? [0] : null));
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function start(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, ?string $logPath = null, bool $interactive = false): RunningProcess
    {
        return $this->executor->start($this->command($arguments, $cwd, $env, $input, $timeout, $logPath, $interactive));
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function compose(string $project, string $file, array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, bool $check = true, ?string $logPath = null, bool $interactive = false): ProcessResult
    {
        return $this->run(['compose', '--project-name', $project, '--file', $file, ...$arguments], $cwd, $env, $input, $timeout, $check, $logPath, $interactive);
    }

    /** @return array<string,mixed> */
    public function validateIdentity(): array
    {
        $information = json_decode($this->run(['info', '--format', '{{json .}}'])->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($information) || ($information['ID'] ?? null) !== $this->target->daemonId) {
            throw new \RuntimeException('Docker daemon identity changed; retained ownership must be recovered against the selected daemon.');
        }
        return $information;
    }

    public function requireCompose(): void
    {
        $this->run(['compose', 'version']);
    }

    public function assertLocalPublishedPorts(): void
    {
        if (!str_starts_with($this->target->endpoint, 'unix://') && !str_starts_with($this->target->endpoint, 'npipe://')) {
            throw new \RuntimeException('Docker endpoint does not establish host-local published-port access.');
        }
    }

    public function resolveImage(ImageSelection $selection, bool $pull = true): ResolvedImage
    {
        if ($pull) {
            $this->run(['pull', $selection->reference], timeout: null);
        }
        $id = trim($this->run(['image', 'inspect', '--format', '{{.Id}}', $selection->reference])->stdout);
        return new ResolvedImage($selection, $id, $this->target->daemonId);
    }
}
