<?php

declare(strict_types=1);

namespace App\Library\Application;

final readonly class PathValidationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $error = null,
        public readonly ?string $resolvedPath = null,
        public readonly bool $exists = false,
        public readonly bool $readable = false,
    ) {
    }
}
