<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Metrics;

final class SystemMetricsProviderRegistry
{
    public function __construct(
        private readonly MetricsProvider $metricsProvider,
    ) {}

    public function get(): MetricsProvider
    {
        return $this->metricsProvider;
    }
}
