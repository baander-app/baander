<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenTelemetry;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/**
 * Copies completed spans into the SpanBridge for the server diagnostics
 * (/api/debug/spans and app:server:spans). The tracer provider always registers it.
 */
final readonly class InMemorySpanProcessor implements SpanProcessorInterface
{
    public function __construct(
        private SpanBridge $bridge,
    ) {
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        // No-op
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        $context = $span->getContext();
        $parent = $span->getParentContext();
        $spanData = $span->toSpanData();
        $attributes = $spanData->getAttributes();

        $this->bridge->addSpan([
            'trace_id'       => $context->getTraceID(),
            'span_id'        => $context->getSpanID(),
            'parent_span_id' => $parent->isValid() ? $parent->getSpanID() : null,
            'operation_name' => $span->getName(),
            'service'        => 'baander',
            'kind'           => (string)$span->getKind(),
            'start_time_us'  => (int)($spanData->getStartEpochNanos() / 1000),
            'duration_us'    => (int)(($spanData->getEndEpochNanos() - $spanData->getStartEpochNanos()) / 1000),
            'attributes'     => $attributes->toArray(),
            'status_code'    => $spanData->getStatus()->getCode(),
            'status_message' => $spanData->getStatus()->getDescription(),
            'file_path'      => $attributes->get('code.filepath') ?? $attributes->get('file.path'),
            'line_number'    => $attributes->get('code.lineno') ?? $attributes->get('file.line'),
        ]);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
