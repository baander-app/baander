<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\Target;
use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\RunningProcess;

class DevelopmentCommandExecutor implements CommandExecutor
{
    private ?ProcessRunner $processRunner = null;
    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        return $policy->complete($this->execute(
            $command->argv,
            $command->cwd,
            $command->environment,
            $command->stdin,
            $command->timeout,
            false,
            $command->logPath,
            $command->passthrough,
        ), $command);
    }

    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function execute(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        bool $check = true,
        ?string $logPath = null,
        bool $interactive = false,
    ): ProcessResult {
        $command = new ProcessSpec($arguments, cwd: $cwd, environment: array_replace(Environment::clean(), $env ?? []), stdin: $input, timeout: $timeout, logPath: $logPath, passthrough: $interactive, processGroup: !$interactive);
        $result = new ProcessRunner()->run($command);
        return new CompletionPolicy($check ? [0] : null)->complete($result, $command);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        return ($this->processRunner ??= new ProcessRunner())->start($command);
    }

    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        return $policy->complete(($this->processRunner ??= new ProcessRunner())->wait($process));
    }

    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        $process->cancel($signal);
        return ($this->processRunner ??= new ProcessRunner())->wait($process);
    }

    /** @return array<string,mixed> */
    public static function target(string $executable = '/bin/docker', ?string $context = 'fixture', string $daemonId = 'daemon'): array
    {
        if (!str_starts_with($executable, '/')) {
            $executable = '/bin/' . $executable;
        }
        return new Target($executable, 'unix:///var/run/docker.sock', $daemonId, $context, null)->toArray();
    }

    /** @param array<string,mixed> $release
     * @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public static function release(array $release, array $instance = []): array
    {
        $target = $instance['docker'] ?? self::target();
        $directory = sys_get_temp_dir() . '/baander-release-evidence-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        register_shutdown_function(static function () use ($directory): void {
            if (!is_dir($directory)) {
                return;
            }
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        });
        $source = $release['source'] ?? null;
        if (!is_string($source) || !str_starts_with($source, sys_get_temp_dir() . '/') || !is_dir($source) || is_link($source)) {
            $source = $directory . '/source';
            mkdir($source, 0700);
            file_put_contents($source . '/Dockerfile', 'ARG APP_HOME');
        }
        $environment = $release['environmentFile'] ?? $directory . '/env.json';
        if (!is_file($environment)) {
            file_put_contents($environment, '{}');
            chmod($environment, 0600);
        }
        $release = array_replace([
            'version' => 1,
            'revision' => 'fixture-revision',
            'layout' => ['version' => 1, 'root' => '/var/www/html'],
            'target' => $target,
            'effective' => [
                'media' => $instance['media'] ?? [],
                'libraries' => $instance['libraries'] ?? [],
                'budgets' => \Baander\Tooling\Configuration\ToolingConfiguration::resolve()->budgets->toArray(),
                'provenance' => [],
                'state_mounts' => $instance['state_mounts'] ?? [],
                'volumes' => $instance['volumes'] ?? [],
                'postgres_image' => $instance['postgres_image'] ?? $release['postgres_image'] ?? $release['images']['postgres_image']['resolvedId'] ?? 'fixture-postgres_image',
                'redis_image' => $instance['redis_image'] ?? $release['redis_image'] ?? $release['images']['redis_image']['resolvedId'] ?? 'fixture-redis_image',
            ],
            'inputs' => [],
        ], $release);
        $release['source'] = $source;
        $release['environmentFile'] = $environment;
        $release['inputs'][$environment] = hash_file('sha256', $environment);
        $identity = \Baander\Tooling\Development\ReleaseContract::sourceIdentity($source);
        $release['sourceIdentity'] = $identity;
        $release['sourceDigest'] = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $existingImages = $release['images'] ?? [];
        $release['images'] = [];
        foreach (\Baander\Tooling\Development\ReleaseContract::IMAGE_KEYS as $key) {
            $identity = $release[$key] ?? $existingImages[$key]['resolvedId'] ?? 'fixture-' . $key;
            $release['images'][$key] = [
                'reference' => array_key_exists($key, $release) ? $identity : ($existingImages[$key]['reference'] ?? $identity),
                'resolvedId' => $identity,
                'capabilities' => $existingImages[$key]['capabilities'] ?? [],
                'daemonId' => $target['daemonId'],
            ];
            unset($release[$key]);
        }
        return \Baander\Tooling\Development\ReleaseContract::fromArray($release)->toArray();
    }
}
