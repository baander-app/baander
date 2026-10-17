<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

final readonly class GenerateImageDerivativeCommand
{
    public function __construct(public string $sourcePath, public ?string $preset = null)
    {
        if ($preset !== null && !in_array($preset, ['thumb', 'small', 'medium', 'large'], true)) {
            throw new \InvalidArgumentException('Unknown image preset.');
        }
    }
}
