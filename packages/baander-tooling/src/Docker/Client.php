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
    private string $builder;

    /** @param array<string,string> $environment */
    public function __construct(public CommandExecutor $executor, public Target $target, private array $environment)
    {
        $this->builder = 'default';
    }

    /**
     * Returns a client whose builds use the named buildx builder. The builder must run its BuildKit container through
     * the docker-container driver on the captured daemon, so a builder name can never move a build to another engine.
     */
    public function withBuilder(string $name): self
    {
        if ($name === $this->builder) {
            return $this;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid buildx builder name: ' . $name);
        }
        $inspection = $this->run(['buildx', 'inspect', $name], check: false);
        if ($inspection->exitCode !== 0) {
            throw new \RuntimeException('Buildx builder ' . $name . ' cannot be inspected: ' . trim($inspection->stderr));
        }
        preg_match_all('/^(Name|Driver|Endpoint):[ \t]*(\S*)[ \t]*$/m', $inspection->stdout, $fields);
        $field = static fn(string $key): array => array_values(array_intersect_key($fields[2], array_keys($fields[1], $key, true) |> array_flip(...)));
        $endpoints = $field('Endpoint');
        if (($field('Name')[0] ?? null) !== $name || $field('Driver') !== ['docker-container'] || $endpoints === [] || array_unique($endpoints) !== [$this->target->endpoint]) {
            throw new \RuntimeException('Buildx builder ' . $name . ' must use the docker-container driver on the captured Docker daemon ' . $this->target->endpoint . '.');
        }
        return clone($this, ['builder' => $name]);
    }

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
    public function command(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, ?string $logPath = null, bool $interactive = false, ?string $stderrLogPath = null, float $terminationGrace = .25, bool $appendLog = false): ProcessSpec
    {
        $this->target->assertSnapshot();
        $environment = self::transportEnvironment(array_replace($this->environment, $env ?? []));
        $buildOffset = self::buildOffset($arguments);
        if ($buildOffset !== null) {
            $arguments = $this->pinBuilder($arguments, $buildOffset);
            unset($environment['BUILDX_BUILDER'], $environment['BUILDKIT_HOST']);
            $environment['DOCKER_BUILDKIT'] = '1';
        }
        return new ProcessSpec(
            [$this->target->executable, '--host', $this->target->endpoint, ...$this->target->transportArguments, ...$arguments],
            cwd: $cwd,
            environment: $environment,
            timeout: $timeout,
            stdin: $input,
            logPath: $logPath,
            passthrough: $interactive,
            processGroup: !$interactive,
            replaceEnvironment: true,
            stderrLogPath: $stderrLogPath,
            terminationGrace: $terminationGrace,
            appendLog: $appendLog,
        );
    }

    /** @param list<string> $arguments */
    private static function buildOffset(array $arguments): ?int
    {
        if (($arguments[0] ?? null) === 'build') {
            return 1;
        }
        if (!in_array($arguments[0] ?? null, ['buildx', 'builder', 'image'], true)) {
            return null;
        }
        for ($index = 1; $index < count($arguments); ++$index) {
            if ($arguments[$index] === 'build') {
                return $index + 1;
            }
            if ($arguments[$index] === '--builder') {
                ++$index;
            } elseif (!str_starts_with($arguments[$index], '--builder=')
                && !in_array($arguments[$index], ['-D', '--debug'], true)
                && !str_starts_with($arguments[$index], '--debug=')) {
                return null;
            }
        }
        return null;
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function pinBuilder(array $arguments, int $offset): array
    {
        $selected = false;
        for ($index = 0; $index < count($arguments) && $arguments[$index] !== '--'; ++$index) {
            if ($arguments[$index] === '--builder') {
                $builder = $arguments[++$index] ?? null;
            } elseif (str_starts_with($arguments[$index], '--builder=')) {
                $builder = substr($arguments[$index], strlen('--builder='));
            } else {
                continue;
            }
            if ($selected || $builder !== $this->builder) {
                throw new \InvalidArgumentException('Builds require exactly one ' . $this->builder . ' builder on the captured Docker daemon.');
            }
            $selected = true;
        }
        return $selected ? $arguments : [...array_slice($arguments, 0, $offset), '--builder', $this->builder, ...array_slice($arguments, $offset)];
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function run(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, bool $check = true, ?string $logPath = null, bool $interactive = false, ?string $stderrLogPath = null, float $terminationGrace = .25, bool $appendLog = false): ProcessResult
    {
        return $this->executor->run($this->command($arguments, $cwd, $env, $input, $timeout, $logPath, $interactive, $stderrLogPath, $terminationGrace, $appendLog), new CompletionPolicy($check ? [0] : null));
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function start(array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, ?string $logPath = null, bool $interactive = false, ?string $stderrLogPath = null, float $terminationGrace = .25, bool $appendLog = false): RunningProcess
    {
        return $this->executor->start($this->command($arguments, $cwd, $env, $input, $timeout, $logPath, $interactive, $stderrLogPath, $terminationGrace, $appendLog));
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function compose(string $project, string $file, array $arguments, ?string $cwd = null, ?array $env = null, ?string $input = null, ?float $timeout = null, bool $check = true, ?string $logPath = null, bool $interactive = false, bool $appendLog = false): ProcessResult
    {
        return $this->run(['compose', '--project-name', $project, '--file', $file, ...$arguments], $cwd, $env, $input, $timeout, $check, $logPath, $interactive, appendLog: $appendLog);
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
