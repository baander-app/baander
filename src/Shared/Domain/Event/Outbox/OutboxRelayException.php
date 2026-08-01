<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use RuntimeException;

/**
 * Aggregates one or more outbox relay failures so that the handler can
 * report every failed event in a single exception instead of failing on
 * the first row and abandoning the rest of the batch.
 */
final class OutboxRelayException extends RuntimeException
{
    /**
     * @param array<int, array{id: int, event: string, error: string}> $failures
     */
    public function __construct(
        string $message,
        private readonly array $failures,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<int, array{id: int, event: string, error: string}>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }
}
