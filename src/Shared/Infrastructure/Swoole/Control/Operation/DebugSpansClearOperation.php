<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\OpenTelemetry\SpanBridge;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;

/** Empties the span buffer, which every HTTP worker shares. */
final readonly class DebugSpansClearOperation implements ServerControlOperation
{
    public const string NAME = 'debug.spans.clear';

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

    public function handle(array $payload): bool
    {
        $this->spans->clear();

        return true;
    }
}
