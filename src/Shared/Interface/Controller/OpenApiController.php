<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Port\OpenApiExporterInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class OpenApiController
{
    public function __construct(private OpenApiExporterInterface $exporter) {}

    public function __invoke(): Response
    {
        return new Response(
            $this->exporter->render(),
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
