<?php

namespace App\Modules\OpenTelemetry\Listeners;

use App\Modules\OpenTelemetry\SpanBuilder;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\StatusCode;

class CustomEventListener
{
    public function handleCustomEvent(string $eventName, array $payload = []): void
    {
        SpanBuilder::create("event.{$eventName}")
            ->asInternal()
            ->attributes([
                'event.name' => $eventName,
                'event.payload' => json_encode($payload),
                'event.timestamp' => now()->toISOString(),
            ])
            ->tags([
                'event.type' => 'custom',
                'event.name' => $eventName,
            ])
            ->trace(function ($span) use ($eventName, $payload) {
                Log::channel('otel_debug')->info('Custom event fired', [
                    'event_name' => $eventName,
                    'payload' => $payload,
                ]);
            });
    }

    public function handleModelEvent(string $model, string $event, array $attributes = []): void
    {
        SpanBuilder::create("model.{$event}")
            ->asInternal()
            ->attributes([
                'model.class' => $model,
                'model.event' => $event,
                'model.attributes' => json_encode($attributes),
            ])
            ->tags([
                'model.class' => class_basename($model),
                'model.event' => $event,
            ])
            ->trace(function ($span) use ($model, $event, $attributes) {
                Log::channel('otel_debug')->info('Model event fired', [
                    'model' => class_basename($model),
                    'event' => $event,
                    'attributes' => $attributes,
                ]);
            });
    }
}