<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';
    case NotAvailable = 'not_available';
}
