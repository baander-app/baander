<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Adds a correlation_id to every log record within the same request/task lifecycle.
 *
 * Resolution order:
 *  1. Explicit ID set via setCorrelationId() (used by Messenger stamp propagation)
 *  2. X-Correlation-ID HTTP request header
 *  3. Generated random ID (once per lifecycle, then reused)
 *
 * Tagged with kernel.reset so the Swoole bundle proxifies this service into a
 * per-coroutine pool. Each coroutine/task gets its own instance with its own
 * $correlationId state. The ID is resolved lazily on first log write and cleared
 * when the pool entry is released (via reset()).
 */
final class CorrelationIdProcessor implements ProcessorInterface, ResetInterface
{
    public const string HEADER = 'X-Correlation-ID';

    private ?string $correlationId = null;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->correlationId === null) {
            $request = $this->requestStack->getCurrentRequest();

            if ($request !== null && $request->headers->has(self::HEADER)) {
                $this->correlationId = $request->headers->get(self::HEADER);
            } else {
                $this->correlationId = bin2hex(random_bytes(16));
            }
        }

        $record->extra['correlation_id'] = $this->correlationId;

        return $record;
    }

    /**
     * Set the correlation ID explicitly (e.g. from a Messenger stamp).
     * Must be called before any log write in the target context.
     */
    public function setCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function getCurrentCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function reset(): void
    {
        $this->correlationId = null;
    }
}
