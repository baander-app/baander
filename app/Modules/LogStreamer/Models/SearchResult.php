<?php

namespace App\Modules\LogStreamer\Models;

use Spatie\LaravelData\Data;

class SearchResult extends Data
{
    public function __construct(
        public readonly int $line,
        public readonly string $content,
        public readonly int $position,
        public readonly ?array $matches = null,
    ) {}

    public static function create(int $line, string $content, int $position, ?array $matches = null): self
    {
        return new self(
            line: $line,
            content: rtrim($content),
            position: $position,
            matches: $matches,
        );
    }

    public function getPreview(int $maxLength = 100): string
    {
        if (strlen($this->content) <= $maxLength) {
            return $this->content;
        }

        return substr($this->content, 0, $maxLength) . '...';
    }
}