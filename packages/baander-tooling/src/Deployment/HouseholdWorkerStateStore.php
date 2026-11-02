<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};

final readonly class HouseholdWorkerStateStore implements WorkerStateStore
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
