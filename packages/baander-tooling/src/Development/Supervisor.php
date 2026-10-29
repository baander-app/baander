<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;

final class Supervisor
{
    public readonly string $unit;

    public function __construct(
        private readonly StateStore $store,
        private readonly Runner $runner,
        private readonly ?string $sourceRoot = null,
        private readonly ?string $phpExecutable = null,
    ) {
        $this->unit = 'baander-development-' . $store->scope();
    }

    public static function systemdArgument(string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
            throw new WorkflowError('Controller paths must not contain control characters.');
        }
        return '"' . str_replace(['\\', '"', '%', '$'], ['\\\\', '\\"', '%%', '$$'], $value) . '"';
    }

    public static function systemdSearchPath(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new WorkflowError('Controller search paths must not contain control characters.');
        }
        $entries = explode(PATH_SEPARATOR, $value)
            |> (static fn(array $entries): array => array_filter($entries, static fn(string $entry): bool => str_starts_with($entry, '/')))
            |> array_unique(...)
            |> array_values(...);
        if ($entries === []) {
            throw new WorkflowError('Controller search paths require at least one absolute directory.');
        }
        return '"' . str_replace(['\\', '"', '%'], ['\\\\', '\\"', '%%'], 'PATH=' . implode(PATH_SEPARATOR, $entries)) . '"';
    }

    public function install(): void
    {
        $searchPath = self::systemdSearchPath(getenv('PATH') ?: '/usr/bin:/bin');
        $php = realpath($this->phpExecutable ?? PHP_BINARY);
        if ($php === false || !is_executable($php)) {
            throw new WorkflowError('The copied controller requires an absolute PHP executable.');
        }
        $root = $this->sourceRoot ?? dirname(__DIR__, 4);
        if (!is_file($root . '/scripts/dev-environment.php')) {
            throw new WorkflowError('The PHP development controller entrypoint is missing.');
        }
        $this->runner->run(['systemctl', '--user', 'show-environment']);
        $payload = new ControllerPayload($this->store->root);
        $payload->publish($root);
        $unitHome = (getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') . '/.config')) . '/systemd/user';
        PrivateDirectory::ensure($unitHome);
        $arguments = [
            $php,
            $payload->launcher(),
            '--state-root',
            $this->store->root,
            'update',
            '--instance',
            'persistent',
            '--automatic',
        ];
        $command = $arguments
            |> (static fn(array $arguments): array => array_map(self::systemdArgument(...), $arguments))
            |> (static fn(array $arguments): string => implode(' ', $arguments));
        $template = (string) file_get_contents($root . '/docker/dev/systemd/baander-development.service.template');
        AtomicFile::write($unitHome . '/' . $this->unit . '.service', str_replace(['${COMMAND}', '${SEARCH_PATH}'], [$command, $searchPath], $template));
        $timer = (string) file_get_contents($root . '/docker/dev/systemd/baander-development.timer');
        AtomicFile::write($unitHome . '/' . $this->unit . '.timer', str_replace('Unit=baander-development.service', 'Unit=' . $this->unit . '.service', $timer));
        $this->runner->run(['systemctl', '--user', 'daemon-reload']);
    }

    /** Fence systemd starts and join its old invocation before taking an instance lock.
     * Direct legacy invocations still require independent proof before conversion.
     */
    public function fenceLegacyService(float $timeoutSeconds = 60): void
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new WorkflowError('Legacy controller completion requires a positive finite timeout.');
        }
        $this->runner->run(['systemctl', '--user', 'stop', $this->unit . '.timer']);
        try {
            $this->install();
            $deadline = hrtime(true) / 1_000_000_000 + $timeoutSeconds;
            do {
                $state = $this->runner->run(
                    ['systemctl', '--user', 'show', '--property=ActiveState', '--value', $this->unit . '.service'],
                    timeout: max(0.001, $deadline - hrtime(true) / 1_000_000_000),
                );
                if (in_array(trim($state->stdout), ['inactive', 'failed'], true)) {
                    return;
                }
                if (hrtime(true) / 1_000_000_000 >= $deadline) {
                    throw new WorkflowError('The old controller service is still running; let it finish before retrying retained-state conversion.');
                }
                usleep(50_000);
            } while (true);
        } finally {
            $this->runner->run([
                'systemctl', '--user', $this->store->enabled('persistent') ? 'enable' : 'disable',
                '--now', $this->unit . '.timer',
            ]);
        }
    }

    public function refresh(): void
    {
        $this->install();
        $this->runner->run([
            'systemctl',
            '--user',
            $this->store->enabled('persistent') ? 'enable' : 'disable',
            '--now',
            $this->unit . '.timer',
        ]);
    }

    public function enable(): void
    {
        $this->store->setEnabled('persistent', true);
        try {
            $this->refresh();
        } catch (WorkflowError $error) {
            $this->store->setEnabled('persistent', false);
            throw $error;
        }
    }

    public function disable(): void
    {
        $this->store->setEnabled('persistent', false);
        $this->runner->run(['systemctl', '--user', 'disable', '--now', $this->unit . '.timer'], check: false);
    }
}
