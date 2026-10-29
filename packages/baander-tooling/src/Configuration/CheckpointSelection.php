<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final readonly class CheckpointSelection
{
    public function __construct(public string $name, public string $path) {}
}
