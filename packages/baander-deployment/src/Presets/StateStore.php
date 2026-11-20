<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

use Baander\Filesystem\AtomicFile;
use Baander\Filesystem\FileLock;
use Baander\Filesystem\PrivateDirectory;

final readonly class StateStore
{
    public function __construct(public string $directory)
    {
        if (!str_starts_with($directory, '/')) {
            throw new \InvalidArgumentException('Preset state directory must be absolute.');
        }
        PrivateDirectory::prepare($directory);
    }

    public function lock(): FileLock
    {
        return FileLock::acquire($this->directory . '/operation.lock');
    }

    /** @return array<string,mixed>|null */
    public function load(): ?array
    {
        $path = $this->directory . '/state.json';
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        $state = AtomicFile::readJson($path);
        if (($state['version'] ?? null) !== 1) {
            throw new \RuntimeException('Unsupported preset state; preserve it for recovery.');
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    public function save(array $state): void
    {
        AtomicFile::writeJson($this->directory . '/state.json', $state);
    }

    public function workers(): WorkerStateStore
    {
        return new WorkerStateStore($this->directory . '/workers');
    }
}
