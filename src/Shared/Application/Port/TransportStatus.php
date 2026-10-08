<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

final readonly class TransportStatus
{
    public function __construct(
        /** Entries in the async Redis stream. */
        public int $asyncQueueDepth,
        /** Messages held by the failure transport, those waiting out a retry delay included. */
        public int $failedQueueDepth,
        /** The consumer name this container is configured with. */
        public string $consumerName,
        /** Whether the consumer group lists that consumer (best effort). */
        public bool $consumerRunning,
    ) {
    }
}
