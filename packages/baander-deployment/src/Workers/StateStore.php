<?php

declare(strict_types=1);

namespace Baander\Deployment\Workers;

interface StateStore
{
    public function directory(): string;

    /** @return array<string,mixed>|null */
    public function load(): ?array;

    /** @param array<string,mixed> $record */
    public function save(array $record): void;
}
