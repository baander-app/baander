<?php

declare(strict_types=1);

namespace Baander\Configuration\Execution;

final readonly class CheckpointSelection
{
    public function __construct(public string $name, public string $path) {}
}
