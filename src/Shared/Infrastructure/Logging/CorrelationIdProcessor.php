<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use App\Shared\Application\Http\BaanderHeader;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Adds a correlation_id to every log record within the same request/task lifecycle.
 *
 * Resolution order:
 *  1. Explicit ID set via setCorrelationId() (used by Messenger stamp propagation)
 *  2. X-Baander-Correlation-ID HTTP request header, when it is at most 512 bytes and holds
 *     no control character; a longer or binary header is replaced like a missing one, so
 *     the Messenger stamp and every log record carry the bounded value
 *  3. Generated random ID (once per lifecycle, then reused)
 *
 * Tagged with kernel.reset so the Swoole bundle proxifies this service into a
 * per-coroutine pool. Each coroutine/task gets its own instance with its own
 * $correlationId state. The ID is resolved lazily on first log write and cleared
 * when the pool entry is released (via reset()).
 */
final class CorrelationIdProcessor implements ProcessorInterface, ResetInterface
{
    public const int MAXIMUM_BYTES = 512;

    private ?string $correlationId = null;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->correlationId === null) {
            $header = $this->requestStack->getCurrentRequest()?->headers->get(BaanderHeader::CorrelationId->value);
            $this->correlationId = is_string($header) && self::acceptable($header) ? $header : bin2hex(random_bytes(16));
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

    private static function acceptable(string $correlationId): bool
    {
        return $correlationId !== '' && strlen($correlationId) <= self::MAXIMUM_BYTES && preg_match('/[\x00-\x1F\x7F]/', $correlationId) !== 1;
    }
}
