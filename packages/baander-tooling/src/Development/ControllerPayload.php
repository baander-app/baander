<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\FileLock;
use Baander\Tooling\Filesystem\PrivateDirectory;

final class ControllerPayload
{
    private readonly string $root;

    public function __construct(string $stateRoot)
    {
        $this->root = $stateRoot . '/controller';
    }

    /** @return list<string> Unleased dispatchers require a separately proven first-upgrade fence. */
    public function legacyDispatchers(): array
    {
        $paths = [];
        foreach (['scripts/dev-environment.php', 'scripts/dev-environment.py'] as $relative) {
            $path = $this->root . '/' . $relative;
            PrivateDirectory::assertNoSymlinks($path);
            if (is_file($path)) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    public function launcher(): string
    {
        return $this->root . '/launcher.php';
    }

    public function selectedGeneration(): ?string
    {
        PrivateDirectory::assertNoSymlinks($this->root . '/selected.json');
        if (!file_exists($this->root . '/selected.json')) {
            return null;
        }
        $selection = AtomicFile::readJson($this->root . '/selected.json');
        $id = $selection['generation'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new WorkflowError('Invalid controller generation selector.');
        }
        return $id;
    }

    public function publish(string $sourceRoot): string
    {
        PrivateDirectory::prepare($this->root);
        PrivateDirectory::prepare($this->root . '/generations');
        PrivateDirectory::prepare($this->root . '/leases');
        $id = bin2hex(random_bytes(16));
        $destination = $this->root . '/generations/.stage-' . $id;
        PrivateDirectory::prepare($destination);
        $staged = true;
        try {
            foreach (['packages/baander-tooling', 'scripts/vendor', 'docker/dev'] as $relative) {
                PrivateDirectory::assertNoSymlinks($sourceRoot . '/' . $relative);
                $this->copyTree($sourceRoot . '/' . $relative, $destination . '/' . $relative, $sourceRoot);
            }
            foreach (['scripts/bootstrap.php', 'scripts/dev-environment.php'] as $relative) {
                PrivateDirectory::assertNoSymlinks($sourceRoot . '/' . $relative);
                AtomicFile::write($destination . '/' . $relative, $this->read($sourceRoot . '/' . $relative));
            }
            foreach (['docker/dev/systemd/baander-development.service.template', 'docker/dev/systemd/baander-development.timer', 'scripts/vendor/autoload.php', 'packages/baander-tooling/resources/application-layout.json', 'packages/baander-tooling/resources/tooling-defaults.json', 'packages/baander-tooling/resources/process-group.sh', 'packages/baander-tooling/resources/controller-launcher.php'] as $required) {
                if (!is_file($destination . '/' . $required)) {
                    throw new WorkflowError('Incomplete controller payload: ' . $required);
                }
            }
            $compatibility = FileLock::tryAcquire($this->root . '/compatibility.lock', shared: true);
            if ($compatibility === null) {
                throw new WorkflowError('Retained-state conversion is draining controller readers; retry publication after it finishes.');
            }
            try {
                $publication = FileLock::acquire($this->root . '/publication.lock', false);
                try {
                    $complete = $this->root . '/generations/' . $id;
                    if (!rename($destination, $complete)) {
                        throw new WorkflowError('Cannot publish controller generation.');
                    }
                    $staged = false;
                    $destination = $complete;
                    AtomicFile::write($this->launcher(), $this->read($destination . '/packages/baander-tooling/resources/controller-launcher.php'));
                    AtomicFile::writeJson($this->root . '/selected.json', ['generation' => $id]);
                } finally {
                    $publication->release();
                }
            } finally {
                $compatibility->release();
            }
        } finally {
            // Only hidden staging trees are known never to have been selected.
            // A selector durability failure can follow its successful rename.
            if ($staged) {
                $this->removeTree($destination);
            }
        }
        $this->retireUnused();
        return $id;
    }

    public function retireUnused(): void
    {
        $publication = FileLock::acquire($this->root . '/publication.lock', false);
        try {
            $selected = $this->selectedGeneration();
            foreach ($this->generations() as $id) {
                if ($id === $selected) {
                    continue;
                }
                $lease = FileLock::tryAcquire($this->root . '/leases/' . $id . '.lock');
                if ($lease === null) {
                    continue;
                }
                try {
                    $this->removeTree($this->root . '/generations/' . $id);
                } finally {
                    $lease->release();
                }
            }
        } finally {
            $publication->release();
        }
    }

    /** Call before taking any instance lock; retain the barrier through conversion.
     * The selected generation must already support the replacement state contract.
     */
    #[\NoDiscard]
    public function drainReaders(): FileLock
    {
        if ($this->legacyDispatchers() !== []) {
            throw new WorkflowError('Retained controller dispatchers have unleased readers. Preserve the original state and establish a supported-entrypoint fence proving all legacy service and manual invocations finished before conversion.');
        }
        $barrier = FileLock::acquire($this->root . '/compatibility.lock', false);
        try {
            $publication = FileLock::acquire($this->root . '/publication.lock', false);
            try {
                $selected = $this->selectedGeneration();
                $generations = $this->generations();
            } finally {
                $publication->release();
            }
            foreach ($generations as $id) {
                if ($id !== $selected) {
                    $lease = FileLock::acquire($this->root . '/leases/' . $id . '.lock', false);
                    $lease->release();
                }
            }
            return $barrier;
        } catch (\Throwable $error) {
            $barrier->release();
            throw $error;
        }
    }

    /** @return list<string> */
    private function generations(): array
    {
        PrivateDirectory::assertNoSymlinks($this->root . '/generations');
        if (!is_dir($this->root . '/generations')) {
            return [];
        }
        return array_values(array_filter(scandir($this->root . '/generations') ?: [], static fn(string $id): bool => preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1));
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new WorkflowError('Cannot read controller payload: ' . $path);
        }
        return $contents;
    }

    private function copyTree(string $source, string $destination, string $root): void
    {
        PrivateDirectory::prepare($destination);
        $entries = @scandir($source);
        if ($entries === false) {
            throw new WorkflowError('Cannot read controller payload directory.');
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === '__pycache__' || str_ends_with($name, '.pem') || str_ends_with($name, '.key')) {
                continue;
            }
            $from = $source . '/' . $name;
            $to = $destination . '/' . $name;
            if (is_link($from)) {
                if ($from !== $root . '/scripts/vendor/baander/tooling' || realpath($from) !== realpath($root . '/packages/baander-tooling')) {
                    throw new WorkflowError('Refusing symlink controller payload.');
                }
                if (!symlink('../../../packages/baander-tooling', $to)) {
                    throw new WorkflowError('Cannot install controller Composer package link.');
                }
            } elseif (is_dir($from)) {
                $this->copyTree($from, $to, $root);
            } else {
                PrivateDirectory::assertNoSymlinks($from);
                AtomicFile::write($to, $this->read($from));
            }
        }
    }

    private function removeTree(string $path): void
    {
        PrivateDirectory::assertNoSymlinks($path);
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            if (is_link($child)) {
                if (!str_ends_with($child, '/scripts/vendor/baander/tooling') || readlink($child) !== '../../../packages/baander-tooling') {
                    throw new WorkflowError('Refusing symlink controller payload.');
                }
                unlink($child);
            } elseif (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
