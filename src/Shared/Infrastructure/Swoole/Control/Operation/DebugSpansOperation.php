<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\OpenTelemetry\SpanBridge;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;

/**
 * The most recent spans, newest first. Every HTTP worker shares the span buffer,
 * so the accepting worker's answer covers the whole server.
 *
 * Payload: `limit`, the number of spans to return.
 */
final readonly class DebugSpansOperation implements ServerControlOperation
{
    public const string NAME = 'debug.spans';

    public function __construct(
        private SpanBridge $spans,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function fansOut(): bool
    {
        return false;
    }

    /** @return list<array<string, mixed>> */
    public function handle(array $payload): array
    {
        $limit = $payload['limit'] ?? null;

        return $this->spans->getRecentSpans(is_int($limit) ? $limit : 100);
    }
}
