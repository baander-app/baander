<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\FileLock;
use Baander\Tooling\Filesystem\PrivateDirectory;

final class StateStore
{
    public readonly string $root;

    public function __construct(string $root)
    {
        $this->root = Environment::absolutePath($root);
        $this->guard(fn() => PrivateDirectory::assertNoSymlinks($this->root));
    }

    public function scope(): string
    {
        return substr(hash('sha256', $this->root), 0, 12);
    }

    public static function default(): self
    {
        return new self((getenv('XDG_STATE_HOME') ?: (getenv('HOME') . '/.local/state')) . '/baander/development');
    }

    public function directory(string $instanceId): string
    {
        if (preg_match('/\A(?:persistent|preview-[0-9a-f]{16})\z/', $instanceId) !== 1) {
            throw new WorkflowError('Invalid development instance identifier.');
        }
        $directory = $this->root . '/' . $instanceId;
        $this->guard(fn() => PrivateDirectory::assertNoSymlinks($directory));
        return $directory;
    }

    private function prepare(string $instanceId): string
    {
        $directory = $this->directory($instanceId);
        $this->guard(fn() => PrivateDirectory::prepare($directory));
        return $directory;
    }

    /** @return array<string,mixed>|null */
    public function load(string $instanceId): ?array
    {
        $path = $this->directory($instanceId) . '/state.json';
        $this->guard(fn() => PrivateDirectory::assertNoSymlinks($path));
        if (!file_exists($path)) {
            return null;
        }
        $value = $this->guard(fn() => AtomicFile::readJson($path));
        if (($value['id'] ?? null) !== $instanceId) {
            throw new WorkflowError('Instance identity does not match; preserve saved state for recovery.');
        }
        return $value;
    }

    /** @param array<string,mixed> $record */
    public function save(string $instanceId, array $record): void
    {
        if (($record['id'] ?? null) !== $instanceId) {
            throw new WorkflowError('Instance record identity does not match its destination.');
        }
        $path = $this->prepare($instanceId) . '/state.json';
        $this->guard(function () use ($path, $record): void {
            $template = null;
            if (file_exists($path)) {
                PrivateDirectory::assertNoSymlinks($path);
                $content = file_get_contents($path);
                if ($content === false) {
                    throw new WorkflowError('Cannot read saved instance; preserve it for recovery.');
                }
                try {
                    $template = json_decode($content, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException $error) {
                    throw new WorkflowError('Cannot read saved instance; preserve it for recovery.', previous: $error);
                }
            }
            AtomicFile::writeJson($path, $this->preserveObjects($record, $template));
        });
    }

    /** @param array<array-key,mixed> $value
     * @return array<array-key,mixed>
     */
    private function preserveObjects(array $value, mixed $template): array
    {
        $maps = [
            'environment',
            'migration_hashes',
            'image_pins',
            'state_mounts',
            'volumes',
            'adoption_inventory',
            'containers',
            'extensions',
        ];
        foreach ($value as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $previous = $template instanceof \stdClass ? ($template->{$key} ?? null)
                : (is_array($template) ? ($template[$key] ?? null) : null);
            if ($item === [] && ($previous instanceof \stdClass || in_array($key, $maps, true))) {
                $value[$key] = new \stdClass();
            } else {
                $value[$key] = $this->preserveObjects($item, $previous);
            }
        }
        return $value;
    }

    public function setEnabled(string $instanceId, bool $enabled): void
    {
        $this->guard(fn() => AtomicFile::writeJson($this->prepare($instanceId) . '/intent.json', ['enabled' => $enabled]));
    }

    public function enabled(string $instanceId): bool
    {
        $path = $this->directory($instanceId) . '/intent.json';
        $this->guard(fn() => PrivateDirectory::assertNoSymlinks($path));
        if (!file_exists($path)) {
            return false;
        }
        $value = $this->guard(fn() => AtomicFile::readJson($path));
        if (!is_bool($value['enabled'] ?? null)) {
            throw new WorkflowError('Invalid updater intent; inspect saved state before continuing.');
        }
        return $value['enabled'];
    }

    public function lock(string $instanceId, bool $blocking = true): FileLock
    {
        return $this->guard(fn() => FileLock::acquire($this->prepare($instanceId) . '/operation.lock', !$blocking));
    }

    /** @return array<string,mixed> */
    public function resolveInstance(string $worktree, string $repository, ?string $selected = null): array
    {
        $identity = $selected ?? Environment::previewId($worktree, $repository);
        $record = $this->load($identity);
        if ($record === null) {
            throw new WorkflowError('No configured instance. Run make ' . ($identity === 'persistent' ? 'dev-up' : 'preview-up') . ' first.');
        }
        if (($record['repository'] ?? $repository) !== $repository) {
            throw new WorkflowError('The saved instance belongs to another repository.');
        }
        return $record;
    }

    /** @template T
     * @param callable():T $operation
     * @return T
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\RuntimeException $error) {
            throw new WorkflowError($error->getMessage(), previous: $error);
        }
    }
}
