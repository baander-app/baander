<?php

namespace App\Modules\Logging\LogStreamer\Models;

use Spatie\LaravelData\Data;

class SearchResults extends Data
{
    public function __construct(
        /** @var SearchResult[] */
        public readonly array $results,
        public readonly string $pattern,
        public readonly bool $caseSensitive,
        public readonly int $totalMatches,
        public readonly float $searchTimeMs,
        public readonly bool $hasMoreResults,
    ) {}

    public static function create(
        array $results,
        string $pattern,
        bool $caseSensitive,
        float $searchTimeMs,
        bool $hasMoreResults = false
    ): self {
        return new self(
            results: SearchResult::collect($results),
            pattern: $pattern,
            caseSensitive: $caseSensitive,
            totalMatches: count($results),
            searchTimeMs: $searchTimeMs,
            hasMoreResults: $hasMoreResults,
        );
    }

    public function isEmpty(): bool
    {
        return $this->totalMatches === 0;
    }

    public function getFirstResult(): ?SearchResult
    {
        return $this->results->first();
    }

    public function getLastResult(): ?SearchResult
    {
        return $this->results->last();
    }
}