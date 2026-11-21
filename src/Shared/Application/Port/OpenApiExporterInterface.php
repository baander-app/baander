<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface OpenApiExporterInterface
{
    public function render(string $format = 'json'): string;
}
