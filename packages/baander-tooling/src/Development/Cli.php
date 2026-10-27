<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\CommandLine\Words;
use Baander\Tooling\Development\Build\Source;

final class Cli
{
    private const array COMMANDS = ['host-setup', 'up', 'status', 'down', 'update', 'recover', 'logs', 'restart', 'exec', 'url'];

    /** @param list<string> $arguments
     * @return array<string,mixed>
     */
    public static function parse(array $arguments): array
    {
        $options = [
            'state-root' => null,
            'instance' => null,
            'media' => [],
            'share-path' => [],
            'docker-context' => 'default',
            'environment-file' => null,
            'adopt' => null,
            'install-trust' => false,
            'inspect' => false,
            'json' => false,
            'automatic' => false,
            'retry' => false,
            'follow' => false,
            'service' => 'app',
            'user' => null,
            'command-env' => null,
            'shell-command-env' => null,
            'arguments' => [],
            'help' => false,
        ];
        $position = 0;
        while (isset($arguments[$position]) && str_starts_with($arguments[$position], '-')) {
            $option = $arguments[$position++];
            if (in_array($option, ['--help', '-h'], true)) {
                return [...$options, 'help' => true, 'command' => null];
            }
            if ($option === '--state-root') {
                $options['state-root'] = self::value($arguments, $position, $option);
            } elseif (str_starts_with($option, '--state-root=')) {
                $options['state-root'] = substr($option, 13);
            } else {
                throw new \InvalidArgumentException('Unknown global option: ' . $option);
            }
        }
        $command = $arguments[$position++] ?? '';
        if (!in_array($command, self::COMMANDS, true)) {
            throw new \InvalidArgumentException('Choose a development command: ' . implode(', ', self::COMMANDS));
        }
        $options['command'] = $command;
        $flags = match ($command) {
            'host-setup' => ['install-trust', 'inspect'], 'status' => ['json'],
            'update' => ['automatic', 'retry'], 'logs' => ['follow'], default => [],
        };
        $values = match ($command) {
            'host-setup' => ['share-path'],
            'up' => ['instance', 'media', 'docker-context', 'environment-file', 'adopt'],
            'exec' => ['instance', 'service', 'user', 'command-env', 'shell-command-env'],
            'logs', 'restart' => ['instance', 'service'], default => ['instance'],
        };
        while (isset($arguments[$position])) {
            $argument = $arguments[$position++];
            if ($command === 'exec' && ($argument === '--' || !str_starts_with($argument, '-'))) {
                $options['arguments'] = [...($argument === '--' ? [] : [$argument]), ...array_slice($arguments, $position)];
                break;
            }
            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;
                continue;
            }
            [$name, $inline] = array_pad(explode('=', substr($argument, 2), 2), 2, null);
            if (str_starts_with($argument, '--') && in_array($name, $flags, true) && $inline === null) {
                $options[$name] = true;
            } elseif (str_starts_with($argument, '--') && in_array($name, $values, true)) {
                $value = $inline ?? self::value($arguments, $position, $argument);
                if ($name === 'media' || $name === 'share-path') {
                    $options[$name][] = $value;
                } else {
                    $options[$name] = $value;
                }
            } else {
                throw new \InvalidArgumentException('Unknown ' . $command . ' option: ' . $argument);
            }
        }
        if ($command === 'up' && !in_array($options['instance'], [null, 'persistent'], true)) {
            throw new \InvalidArgumentException('The up command accepts only --instance persistent; omit it for a preview.');
        }
        if (!in_array($options['service'], ['app', 'nginx', 'postgres', 'redis', 'vite'], true)) {
            throw new \InvalidArgumentException('Unknown development service: ' . $options['service']);
        }
        if ($options['command-env'] !== null && $options['shell-command-env'] !== null) {
            throw new \InvalidArgumentException('Choose --command-env or --shell-command-env.');
        }
        return $options;
    }

    /** @param list<string> $arguments */
    private static function value(array $arguments, int &$position, string $option): string
    {
        if (!isset($arguments[$position]) || str_starts_with($arguments[$position], '--')) {
            throw new \InvalidArgumentException('Missing value for ' . $option);
        }
        return $arguments[$position++];
    }

    /** @param array<string,mixed> $options
     * @return non-empty-list<string>
     */
    public static function execCommand(array $options): array
    {
        $command = $options['arguments'];
        $variable = $options['command-env'] ?? $options['shell-command-env'];
        if ($variable !== null) {
            if ($command !== []) {
                throw new WorkflowError('Choose command arguments or a command environment variable.');
            }
            $value = getenv($variable);
            if ($value === false || trim($value) === '') {
                throw new WorkflowError('The command environment variable is empty or missing.');
            }
            $command = $options['shell-command-env'] !== null ? ['bash', '-c', $value] : Words::split($value);
        }
        if ($command === []) {
            throw new WorkflowError('Supply a command after exec --.');
        }
        return $command;
    }

    /** @return array{string,string} */
    public static function repositoryContext(Runner $runner, string $directory): array
    {
        $root = trim($runner->run(['git', 'rev-parse', '--show-toplevel'], cwd: $directory)->stdout);
        $remote = $runner->run(['git', 'remote', 'get-url', 'origin'], cwd: $root, check: false);
        if ($remote->exitCode === 0) {
            $identity = trim($remote->stdout);
        } else {
            $common = trim($runner->run(['git', 'rev-parse', '--git-common-dir'], cwd: $root)->stdout);
            $identity = Environment::resolvedPath(str_starts_with($common, '/') ? $common : $root . '/' . $common);
        }
        return [$root, $identity];
    }

    /** @return array<string,string> */
    public static function dockerTarget(Runner $runner, string $context): array
    {
        $executable = Environment::executable('docker');
        if ($executable === null) {
            throw new WorkflowError('Install Docker with Compose before starting a development instance.');
        }
        $information = json_decode($runner->run([$executable, '--context', $context, 'info', '--format', '{{json .}}'])->stdout, true, flags: JSON_THROW_ON_ERROR);
        $runner->run([$executable, '--context', $context, 'compose', 'version']);
        return ['executable' => $executable, 'context' => $context, 'daemon_id' => $information['ID']];
    }

    private static function within(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, rtrim($parent, '/') . '/');
    }

    public static function validateStateRoot(StateStore $store, string $root): void
    {
        if (self::within(Environment::resolvedPath($store->root), Environment::resolvedPath($root))) {
            throw new WorkflowError('Development state must be outside the worktree.');
        }
    }

    /** @param list<array<string,mixed>> $media */
    public static function validateMediaState(array $media, string $stateRoot): void
    {
        $state = Environment::resolvedPath($stateRoot);
        foreach ($media as $mount) {
            $source = Environment::resolvedPath($mount['source']);
            if (self::within($source, $state) || self::within($state, $source)) {
                throw new WorkflowError('Media directories must not contain development state or be inside it.');
            }
        }
    }

    /** @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function configure(StateStore $store, Runner $runner, string $root, string $repository, array $options): array
    {
        self::validateStateRoot($store, $root);
        $identity = $options['instance'] ?? Environment::previewId($root, $repository);
        $lock = $store->lock($identity);
        try {
            $instance = $store->load($identity);
            if ($instance !== null && $instance['repository'] !== $repository) {
                throw new WorkflowError('The saved instance belongs to another repository.');
            }
            $media = [];
            foreach ($options['media'] as $value) {
                $parts = explode('=', $value, 2);
                $source = Environment::resolvedPath($parts[0]);
                if (!is_dir($source)) {
                    throw new WorkflowError('A media source must be an existing directory.');
                }
                $media[] = ['source' => $source, 'target' => $parts[1] ?? $source];
            }
            if ($instance === null) {
                if ($media === [] && $identity !== 'persistent') {
                    $persistent = $store->load('persistent');
                    $media = ($persistent['repository'] ?? null) === $repository ? ($persistent['media'] ?? []) : [];
                }
                self::validateMediaState($media, $store->root);
                $instance = [
                    'id' => $identity,
                    'kind' => $identity === 'persistent' ? 'persistent' : 'preview',
                    'hostname' => $identity === 'persistent' ? 'baander.test' : $identity . '.baander.test',
                    'project' => 'baander-' . $identity . '-' . $store->scope(),
                    'repository' => $repository,
                    'worktree' => $root,
                    'phase' => 'configured',
                    'media' => $media,
                    'docker' => self::dockerTarget($runner, $options['docker-context']),
                ];
                $store->save($identity, $instance);
            } elseif ($media !== [] && $media !== ($instance['media'] ?? [])) {
                if ($instance['phase'] !== 'stopped') {
                    throw new WorkflowError('Stop this instance before changing its media mounts.');
                }
                $instance['media'] = $media;
            }
            self::validateMediaState($instance['media'] ?? [], $store->root);
            $instance = new Bootstrap($store, $runner)->configure(
                $instance,
                environment: self::jsonOption($options['environment-file']),
                adoption: self::jsonOption($options['adopt']),
            );
            $store->save($identity, $instance);
            return $instance;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed>|null */
    private static function jsonOption(?string $path): ?array
    {
        if ($path === null) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new WorkflowError('Cannot read configuration file: ' . $path);
        }
        $value = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new WorkflowError('Configuration must be a JSON object: ' . $path);
        }
        return $value;
    }

    /** @param array<string,mixed>|null $instance */
    public static function readyUrl(?array $instance): string
    {
        if ($instance === null || ($instance['phase'] ?? null) !== 'ready' || !($instance['readiness'] ?? null)) {
            throw new WorkflowError('Activation stopped before readiness; inspect instance status before opening its hostname.');
        }
        return 'https://' . $instance['hostname'];
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        try {
            $options = self::parse($arguments);
        } catch (\InvalidArgumentException $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            return 2;
        }
        if ($options['help']) {
            echo "Manage isolated Baander development instances.\nUsage: php scripts/dev-environment.php [--state-root PATH] COMMAND [OPTIONS]\n",
            'Commands: ', implode(', ', self::COMMANDS), "\n",
            "Host: --install-trust --inspect --share-path PATH\n",
            "Up: --instance persistent --media SOURCE[=TARGET] --docker-context NAME --environment-file PATH --adopt PATH\n",
            "Instance commands: --instance ID; status: --json; update: --automatic --retry\n",
            "Logs/restart/exec: --service NAME; logs: --follow; exec: --user USER --command-env NAME | --shell-command-env NAME | -- ARGV\n";
            return 0;
        }
        try {
            $store = $options['state-root'] === null ? StateStore::default() : new StateStore($options['state-root']);
            $runner = new Runner();
            $command = $options['command'];
            if ($command === 'host-setup') {
                $worktree = $runner->run(['git', 'rev-parse', '--show-toplevel'], cwd: getcwd(), check: false);
                if ($worktree->exitCode === 0) {
                    self::validateStateRoot($store, trim($worktree->stdout));
                }
                $host = new Host($store, $runner);
                if ($options['share-path'] !== []) {
                    $host->sharePaths($options['share-path']);
                }
                echo 'Host setup instructions: ', $host->instructions(), "/README.md\n";
                if ($options['inspect']) {
                    echo json_encode($host->inspect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
                }
                if ($options['install-trust']) {
                    $host->certificates(installTrust: true);
                }
                return 0;
            }
            if ($command === 'update' && $options['automatic']) {
                if ($options['instance'] !== 'persistent') {
                    throw new WorkflowError('Automatic updates require the persistent instance.');
                }
                new Updater($store, $runner)->update('persistent', automatic: true);
                return 0;
            }
            [$root, $repository] = self::repositoryContext($runner, getcwd());
            if ($command === 'up') {
                if (!is_file($store->root . '/gateway/tls/cert.pem')) {
                    throw new WorkflowError('Complete make dev-host-setup and certificate trust setup before starting an instance.');
                }
                $instance = self::configure($store, $runner, $root, $repository, $options);
                self::enableSupervisor($store, $runner, $instance);
                new Updater($store, $runner)->update($instance['id'], retry: true);
                $url = self::readyUrl($store->load($instance['id']));
                echo 'Ready: ', $url, "\n";
                return 0;
            }
            $instance = $store->resolveInstance($root, $repository, $options['instance']);
            if ($command === 'status') {
                self::status($store, $runner, $instance, $options['json']);
                return 0;
            }
            if ($command === 'url') {
                if ($instance['kind'] !== 'preview' || $instance['phase'] !== 'ready') {
                    throw new WorkflowError("Browser verification requires this worktree's ready isolated preview.");
                }
                echo 'https://', $instance['hostname'], "\n";
                return 0;
            }
            if ($command === 'down') {
                if ($instance['kind'] === 'persistent') {
                    new Supervisor($store, $runner)->disable();
                }
                new Updater($store, $runner)->down($instance['id']);
                return 0;
            }
            if ($command === 'update') {
                new Updater($store, $runner)->update($instance['id'], retry: $options['retry']);
                return 0;
            }
            if ($command === 'recover') {
                new Updater($store, $runner)->recover($instance['id']);
                return 0;
            }
            if ($command === 'restart') {
                $updater = new Updater($store, $runner);
                $updater->down($instance['id']);
                self::enableSupervisor($store, $runner, $instance);
                $updater->update($instance['id'], retry: true);
                return 0;
            }
            if ($command === 'logs') {
                $docker = new Docker($runner, $instance['docker']);
                $docker->validate();
                return $docker->compose(
                    $instance,
                    ['logs', '--tail', '100', ...($options['follow'] ? ['--follow'] : []), $options['service']],
                    check: false,
                    timeout: null,
                    interactive: true,
                )->exitCode;
            }
            $lock = $store->lock($instance['id']);
            try {
                $instance = $store->load($instance['id']);
                if ($instance === null || $instance['phase'] !== 'ready') {
                    throw new WorkflowError('The instance is not ready for commands; inspect its status.');
                }
                $docker = new Docker($runner, $instance['docker']);
                $docker->validate();
                $values = [
                    'exec',
                    ...(stream_isatty(STDIN) ? [] : ['-T']),
                    ...($options['user'] === null ? [] : ['--user', $options['user']]),
                    $options['service'],
                    ...self::execCommand($options),
                ];
                return $docker->compose($instance, $values, check: false, timeout: null, interactive: true)->exitCode;
            } finally {
                $lock->release();
            }
        } catch (\RuntimeException|\InvalidArgumentException|\JsonException $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            return 1;
        }
    }

    /** @param array<string,mixed> $instance */
    private static function enableSupervisor(StateStore $store, Runner $runner, array $instance): void
    {
        if ($instance['kind'] !== 'persistent') {
            return;
        }
        $lock = $store->lock('persistent');
        try {
            new Supervisor($store, $runner)->enable();
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,mixed> $instance */
    private static function status(StateStore $store, Runner $runner, array $instance, bool $json): void
    {
        $data = [];
        foreach ([
            'id',
            'hostname',
            'phase',
            'running_revision',
            'detected_revision',
            'target_revision',
            'last_ready_revision',
            'worker_snapshot',
            'readiness',
            'failure',
        ] as $key) {
            $data[$key] = $instance[$key] ?? null;
        }
        $data['updater_enabled'] = $store->enabled($instance['id']);
        if ($instance['kind'] === 'preview') {
            try {
                $data['worker_snapshot_current'] = Source::snapshotWorktree($runner, $instance['worktree'], null) === ($instance['worker_snapshot'] ?? null);
            } catch (\RuntimeException) {
                $data['worker_snapshot_current'] = 'unavailable';
            }
        }
        if ($json) {
            ksort($data);
            echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        } else {
            foreach ($data as $key => $value) {
                echo $key, ': ', match (true) {
                    $value === null => 'unknown', $value === true => 'True', $value === false => 'False',
                    is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), default => $value,
                }, "\n";
            }
        }
    }
}
