<?php

declare(strict_types=1);

namespace Baander\Deployment\Presets;

use Baander\Filesystem\AtomicFile;
use Baander\Filesystem\PrivateDirectory;
use Baander\Deployment\Workers\StateStore;

final readonly class WorkerStateStore implements StateStore
{
    public function __construct(private string $path)
    {
        PrivateDirectory::prepare($path);
    }

    public function directory(): string
    {
        return $this->path;
    }

    /** @return array<string,mixed>|null */
    public function load(): ?array
    {
        $file = $this->path . '/state.json';
        return file_exists($file) || is_link($file) ? AtomicFile::readJson($file) : null;
    }

    /** @param array<string,mixed> $state */
    public function save(array $state): void
    {
        AtomicFile::writeJson($this->path . '/state.json', $state);
    }
}
