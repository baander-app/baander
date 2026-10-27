<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;

final class Supervisor
{
    public readonly string $unit;

    public function __construct(private readonly StateStore $store, private readonly Runner $runner,
        private readonly ?string $sourceRoot = null, private readonly ?string $phpExecutable = null)
    {
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
        $entries = array_values(array_unique(array_filter(explode(PATH_SEPARATOR, $value), static fn (string $entry): bool => str_starts_with($entry, '/'))));
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
        $root = $this->sourceRoot ?? dirname(__DIR__, 3);
        if (!is_file($root . '/scripts/dev-environment.php')) {
            throw new WorkflowError('The PHP development controller entrypoint is missing.');
        }
        $this->runner->run(['systemctl', '--user', 'show-environment']);
        $controller = $this->store->root . '/controller';
        PrivateDirectory::prepare($controller);
        foreach (['scripts/tooling', 'docker/dev'] as $relative) {
            $this->copyTree($root . '/' . $relative, $controller . '/' . $relative);
        }
        AtomicFile::write($controller . '/scripts/dev-environment.php', (string) file_get_contents($root . '/scripts/dev-environment.php'));
        $unitHome = (getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') . '/.config')) . '/systemd/user';
        PrivateDirectory::ensure($unitHome);
        $arguments = [$php, $controller . '/scripts/dev-environment.php', '--state-root', $this->store->root,
            'update', '--instance', 'persistent', '--automatic'];
        $command = implode(' ', array_map(self::systemdArgument(...), $arguments));
        $template = (string) file_get_contents($root . '/docker/dev/systemd/baander-development.service.template');
        AtomicFile::write($unitHome . '/' . $this->unit . '.service', str_replace(['${COMMAND}', '${SEARCH_PATH}'], [$command, $searchPath], $template));
        $timer = (string) file_get_contents($root . '/docker/dev/systemd/baander-development.timer');
        AtomicFile::write($unitHome . '/' . $this->unit . '.timer', str_replace('Unit=baander-development.service', 'Unit=' . $this->unit . '.service', $timer));
        $this->runner->run(['systemctl', '--user', 'daemon-reload']);
        $this->removeTree($controller . '/scripts/dev_environment');
        $legacy = $controller . '/scripts/dev-environment.py';
        PrivateDirectory::assertNoSymlinks($legacy);
        if (is_file($legacy)) {
            unlink($legacy);
        }
    }

    public function refresh(): void
    {
        $this->install();
        $this->runner->run(['systemctl', '--user', $this->store->enabled('persistent') ? 'enable' : 'disable', '--now', $this->unit . '.timer']);
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

    private function copyTree(string $source, string $destination): void
    {
        PrivateDirectory::prepare($destination);
        foreach (scandir($source) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === '__pycache__' || str_ends_with($name, '.pem') || str_ends_with($name, '.key')) {
                continue;
            }
            $from = $source . '/' . $name;
            if (is_link($from)) {
                throw new WorkflowError('Refusing symlink controller payload.');
            }
            if (is_dir($from)) {
                $this->copyTree($from, $destination . '/' . $name);
            } else {
                AtomicFile::write($destination . '/' . $name, (string) file_get_contents($from));
            }
        }
    }

    private function removeTree(string $path): void
    {
        PrivateDirectory::assertNoSymlinks($path);
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            if (is_link($child)) {
                throw new WorkflowError('Refusing symlink obsolete controller payload.');
            }
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
