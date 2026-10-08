<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenTelemetry;

use Closure;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * Builds the application's tracer provider.
 *
 * Completed spans always go to the diagnostics buffer, so the admin page and
 * app:server:spans work without an external collector. `app.otel.enabled`
 * (OTEL_ENABLED) only adds export to the OTLP endpoint.
 */
final readonly class TracerProviderFactory
{
    /**
     * @param (Closure(string): SpanExporterInterface)|null $exporterFactory builds the exporter for an endpoint; OTLP over HTTP by default
     */
    public function __construct(
        private InMemorySpanProcessor $diagnostics,
        private bool $exportEnabled,
        private string $exporterUrl,
        private string $serviceName,
        private ?Closure $exporterFactory = null,
    ) {
    }

    public function create(): TracerProviderInterface
    {
        $processors = [$this->diagnostics];
        if ($this->exportEnabled) {
            $processors[] = new BatchSpanProcessor($this->exporter(), Clock::getDefault());
        }

        return new TracerProvider(
            $processors,
            new AlwaysOnSampler(),
            ResourceInfo::create(Attributes::create(['service.name' => $this->serviceName])),
        );
    }

    private function exporter(): SpanExporterInterface
    {
        if ($this->exporterFactory !== null) {
            return ($this->exporterFactory)($this->exporterUrl);
        }

        return new SpanExporter((new OtlpHttpTransportFactory())->create($this->exporterUrl, ContentTypes::PROTOBUF));
    }
}
