<?php

declare(strict_types=1);

namespace App\QoL\Domain\Exception;

use RuntimeException;

final class StreamBudgetExhausted extends RuntimeException
{
    public function __construct(
        public readonly int    $activeStreams,
        public readonly float  $budgetUsed,
        public readonly string $requestedTier,
        ?string                $message = null,
    )
    {
        parent::__construct(
            $message ?? sprintf(
            'Stream budget exhausted: %d active streams using %.0f%% capacity, tier "%s" does not fit.',
            $this->activeStreams,
            $this->budgetUsed * 100,
            $this->requestedTier,
        ),
        );
    }

    /**
     * Structured error data for the 503 response body.
     */
    public function toResponseData(): array
    {
        return [
            'error' => 'stream_budget_exhausted',
            'active_streams' => $this->activeStreams,
            'budget_used' => round($this->budgetUsed, 4),
            'requested_tier' => $this->requestedTier,
            'message' => $this->getMessage(),
        ];
    }
}
