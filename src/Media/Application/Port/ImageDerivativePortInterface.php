<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

interface ImageDerivativePortInterface
{
    public function generate(string $sourcePath, ?string $preset): void;
}
