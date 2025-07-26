<?php

declare(strict_types=1);

use OpenTelemetry\API\Signals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

$resource = ResourceInfoFactory::emptyResource()->merge(
    ResourceInfo::create(
        Attributes::create([
            ResourceAttributes::SERVICE_NAME    => $_ENV['OTEL_SERVICE_NAME'] ?? 'baander-backend',
            ResourceAttributes::SERVICE_VERSION => $_ENV['OTEL_SERVICE_VERSION'] ?? '1.0.0',
        ]),
    ),
);

// Check if insecure mode is enabled
$isInsecure = ($_ENV['OTEL_EXPORTER_OTLP_INSECURE'] ?? 'false') === 'true';
$timeout = (int)($_ENV['OTEL_EXPORTER_OTLP_TIMEOUT'] ?? 30);

$transportFactory = new OtlpHttpTransportFactory();

// Use the correct HTTP endpoints instead of gRPC paths
$baseEndpoint = $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'];
$tracesUrl = $baseEndpoint . '/v1/traces';
$metricsUrl = $baseEndpoint . '/v1/metrics';
$logsUrl = $baseEndpoint . '/v1/logs';

// Create exporters with the correct transport options
if ($isInsecure) {
    $spanExporter = new SpanExporter($transportFactory->create(
        $tracesUrl,
        'application/x-protobuf',
        [],  // headers
        null, // compression
        $timeout,
        500,  // retryDelay
        1,    // maxRetries
        null, // cacert - null means no SSL verification
        null, // cert
        null, // key
    ));

    $metricsExporter = new \OpenTelemetry\Contrib\Otlp\MetricExporter($transportFactory->create(
        $metricsUrl,
        'application/x-protobuf',
        [],  // headers
        null, // compression
        $timeout,
        500,  // retryDelay
        1,    // maxRetries
        null, // cacert - null means no SSL verification
        null, // cert
        null, // key
    ));

    $logsExporter = new LogsExporter($transportFactory->create(
        $logsUrl,
        'application/x-protobuf',
        [],  // headers
        null, // compression
        $timeout,
        500,  // retryDelay
        1,    // maxRetries
        null, // cacert - null means no SSL verification
        null, // cert
        null, // key
    ));
} else {
    // For secure mode, use SSL certificates
    $certPath = __DIR__ . '/../docker/dev/juul.localdomain.crt';

    $spanExporter = new SpanExporter($transportFactory->create(
        $tracesUrl,
        'application/x-protobuf',
        [],  // headers
        null, // compression
        $timeout,
        500,  // retryDelay
        1,    // maxRetries
        $certPath, // cacert
        $certPath, // cert
        $certPath, // key
    ));

    $metricsExporter = new \OpenTelemetry\Contrib\Otlp\MetricExporter($transportFactory->create(
        $metricsUrl,
        'application/x-protobuf',
        [],  // headers
        null, // compression
        $timeout,
        500,  // retryDelay
        1,    // maxRetries
        $certPath, // cacert
        $certPath, // cert
        $certPath, // key
    ));

    $logsExporter = new LogsExporter($transportFactory->create(
        $logsUrl,
        'application/x-protobuf',
        [],  // headers
        null, // compression
        $timeout,
        500,  // retryDelay
        1,    // maxRetries
        $certPath, // cacert
        $certPath, // cert
        $certPath, // key
    ));
}

// Create meter provider
$meterProvider = MeterProvider::builder()
    ->setResource($resource)
    ->addReader(new ExportingReader($metricsExporter))
    ->build();

// Create logger provider
$loggerProvider = new LoggerProvider(
    processor: new BatchLogRecordProcessor(
        exporter: $logsExporter,
        clock: \OpenTelemetry\API\Common\Time\Clock::getDefault(),
        maxQueueSize: 1000,
        exportTimeoutMillis: $timeout * 1000,
        maxExportBatchSize: 50,
        meterProvider: $meterProvider,
    ),
    instrumentationScopeFactory: new \OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory(
        new \OpenTelemetry\SDK\Logs\LogRecordLimitsBuilder()->build()->getAttributeFactory()
    ),
);

// Create tracer provider
$tracerProvider = TracerProvider::builder()
    ->addSpanProcessor(
        new BatchSpanProcessor(
            exporter: $spanExporter,
            clock: \OpenTelemetry\API\Common\Time\Clock::getDefault(),
            maxQueueSize: 1000,
            scheduledDelayMillis: 1000,
            exportTimeoutMillis: $timeout * 1000,
            maxExportBatchSize: 50,
            autoFlush: false,
        ),
    )
    ->setResource($resource)
    ->build();

Sdk::builder()
    ->setTracerProvider($tracerProvider)
    ->setMeterProvider($meterProvider)
    ->setLoggerProvider($loggerProvider)
    ->setPropagator(TraceContextPropagator::getInstance())
    ->setAutoShutdown(false)
    ->buildAndRegisterGlobal();

$instrumentation = new \OpenTelemetry\API\Instrumentation\CachedInstrumentation('otel');

return $instrumentation;