<?php

namespace App\Modules\LogStreamer\Models;

use Spatie\LaravelData\Data;

class ContentChunk extends Data
{
    public function __construct(
        public readonly string $content,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly int $totalLines,
        public readonly bool $hasMore,
    ) {}

    public static function create(
        string $content,
        int $startLine,
        int $endLine,
        int $totalLines
    ): self {
        return new self(
            content: $content,
            startLine: $startLine,
            endLine: $endLine,
            totalLines: $totalLines,
            hasMore: $endLine < $totalLines,
        );
    }

    public function getLines(): array
    {
        return explode("\n", $this->content);
    }

    public function getLineCount(): int
    {
        return $this->endLine - $this->startLine + 1;
    }

    public function isEmpty(): bool
    {
        return empty(trim($this->content));
    }
}