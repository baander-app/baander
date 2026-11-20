<?php

declare(strict_types=1);

namespace Baander\DevEnvironment\Controller;

use Baander\Filesystem\AtomicFile;
use Baander\Filesystem\FileLock;
use Baander\Filesystem\PrivateDirectory;
use Baander\Process\Execution\CommandFailure;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\DevEnvironment\WorkflowError;

final class ControllerPayload
{
    private const array LOCAL_PACKAGES = [
        'core', 'filesystem', 'process', 'configuration',
        'containers', 'images', 'deployment', 'dev-environment',
        'dev-qualification', 'dev-ci', 'dev-dependencies', 'dev-registry',
        'dev-website',
    ];

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
            $packages = $this->packageClosure($sourceRoot);
            foreach ([...array_values($packages), 'scripts/vendor', 'docker/dev'] as $relative) {
                PrivateDirectory::assertNoSymlinks($sourceRoot . '/' . $relative);
                $this->copyTree($sourceRoot . '/' . $relative, $destination . '/' . $relative, $sourceRoot, $packages);
            }
            foreach (['scripts/bootstrap.php', 'scripts/dev-environment.php', 'scripts/composer.json'] as $relative) {
                PrivateDirectory::assertNoSymlinks($sourceRoot . '/' . $relative);
                AtomicFile::write($destination . '/' . $relative, $this->read($sourceRoot . '/' . $relative));
            }
            foreach (['docker/dev/systemd/baander-development.service.template', 'docker/dev/systemd/baander-development.timer', 'scripts/vendor/autoload.php', 'packages/baander-configuration/resources/application-layout.json', 'packages/baander-configuration/resources/tooling-defaults.json', 'packages/baander-process/resources/process-group.sh', 'packages/baander-dev-environment/resources/controller-launcher.php'] as $required) {
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
                    AtomicFile::write($this->launcher(), $this->read($destination . '/packages/baander-dev-environment/resources/controller-launcher.php'));
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
    public function drainReaders(float $seconds, ?EngineExecutor $executor = null): FileLock
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new WorkflowError('Controller reader drain requires a positive finite budget.');
        }
        if ($this->legacyDispatchers() !== []) {
            throw new WorkflowError('Retained controller dispatchers have unleased readers. Preserve the original state and establish a supported-entrypoint fence proving all legacy service and manual invocations finished before conversion.');
        }
        $barrier = FileLock::tryAcquire($this->root . '/compatibility.lock');
        if ($barrier === null) {
            throw new WorkflowError('Another conversion is already draining controller readers; retry after it finishes.');
        }
        $ownedExecutor = $executor === null;
        $deadline = hrtime(true) / 1e9 + $seconds;
        try {
            $executor ??= new EngineExecutor(ExecutionContext::bounded($seconds, getenv()));
            $publication = $this->waitForLock($this->root . '/publication.lock', $executor, $deadline);
            try {
                $selected = $this->selectedGeneration();
                $invocation = $GLOBALS['baanderControllerGeneration'] ?? null;
                if (($invocation['root'] ?? null) === $this->root && ($invocation['id'] ?? null) !== $selected) {
                    throw new WorkflowError('Retry conversion through the selected controller generation; this invocation was superseded.');
                }
                $generations = $this->generations();
            } finally {
                $publication->release();
            }
            foreach ($generations as $id) {
                if ($id !== $selected) {
                    $lease = $this->waitForLock($this->root . '/leases/' . $id . '.lock', $executor, $deadline);
                    $lease->release();
                }
            }
            return $barrier;
        } catch (\Throwable $error) {
            $barrier->release();
            throw $error;
        } finally {
            if ($ownedExecutor) {
                $executor?->close();
            }
        }
    }

    private function waitForLock(string $path, EngineExecutor $executor, float $deadline): FileLock
    {
        do {
            $executor->checkpoint();
            if (hrtime(true) / 1e9 >= $deadline) {
                throw new CommandFailure('Controller reader drain budget expired; preserve retained state and retry after older invocations finish.', 124);
            }
            $lock = FileLock::tryAcquire($path);
            if ($lock !== null) {
                return $lock;
            }
            usleep(10_000);
        } while (true);
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

    /** @return array<string,string> */
    private function packageClosure(string $root): array
    {
        $manifest = $this->manifest($root . '/scripts/composer.json');
        $pending = array_replace($manifest['require'] ?? [], $manifest['require-dev'] ?? [])
            |> array_keys(...);
        $packages = [];
        while ($pending !== []) {
            $name = array_pop($pending);
            if (!str_starts_with($name, 'baander/') || isset($packages[$name])) {
                continue;
            }
            $suffix = substr($name, strlen('baander/'));
            if (!in_array($suffix, self::LOCAL_PACKAGES, true)) {
                throw new WorkflowError('Unsupported controller Composer package: ' . $name);
            }
            $relative = 'packages/baander-' . $suffix;
            $package = $this->manifest($root . '/' . $relative . '/composer.json');
            if (($package['name'] ?? null) !== $name) {
                throw new WorkflowError('Controller Composer package name does not match its source: ' . $name);
            }
            $installed = $root . '/scripts/vendor/' . $name;
            if (!is_link($installed) || realpath($installed) !== realpath($root . '/' . $relative)) {
                throw new WorkflowError('Refusing missing or invalid symlink controller payload: ' . $name);
            }
            $packages[$name] = $relative;
            array_push($pending, ...array_keys($package['require'] ?? []));
        }
        if (!isset($packages['baander/dev-environment'])) {
            throw new WorkflowError('Controller Composer project does not require its development environment package.');
        }
        ksort($packages, SORT_STRING);
        return $packages;
    }

    /** @return array<string,mixed> */
    private function manifest(string $path): array
    {
        PrivateDirectory::assertNoSymlinks($path);
        try {
            $manifest = json_decode($this->read($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new WorkflowError('Invalid controller Composer manifest: ' . $path, previous: $error);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new WorkflowError('Invalid controller Composer manifest: ' . $path);
        }
        foreach (['require', 'require-dev'] as $section) {
            $requirements = $manifest[$section] ?? [];
            if (!is_array($requirements)) {
                throw new WorkflowError('Invalid controller Composer requirements: ' . $path);
            }
            foreach ($requirements as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint)) {
                    throw new WorkflowError('Invalid controller Composer requirement: ' . $path);
                }
            }
        }
        return $manifest;
    }

    /** @param array<string,string> $packages */
    private function copyTree(string $source, string $destination, string $root, array $packages): void
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
                $packageName = 'baander/' . basename($from);
                $package = $packages[$packageName] ?? null;
                if ($package === null || $from !== $root . '/scripts/vendor/' . $packageName
                    || realpath($from) !== realpath($root . '/' . $package)) {
                    throw new WorkflowError('Refusing symlink controller payload.');
                }
                if (!symlink('../../../' . $package, $to)) {
                    throw new WorkflowError('Cannot install controller Composer package link.');
                }
            } elseif (is_dir($from)) {
                $this->copyTree($from, $to, $root, $packages);
            } else {
                PrivateDirectory::assertNoSymlinks($from);
                AtomicFile::write($to, $this->read($from));
            }
        }
    }

    private function removeTree(string $path, ?string $generationRoot = null): void
    {
        $generationRoot ??= $path;
        PrivateDirectory::assertNoSymlinks($path);
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            if (is_link($child)) {
                $packageName = basename($child);
                if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $packageName) !== 1
                    || $path !== $generationRoot . '/scripts/vendor/baander'
                    || readlink($child) !== '../../../packages/baander-' . $packageName) {
                    throw new WorkflowError('Refusing symlink controller payload.');
                }
                unlink($child);
            } elseif (is_dir($child)) {
                $this->removeTree($child, $generationRoot);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
