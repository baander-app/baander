<?php

namespace App\Modules\Logging\LogStreamer\Models;

use Spatie\LaravelData\Data;

class FileInfo extends Data
{
    public function __construct(
        public readonly int $size,
        public readonly float $sizeMb,
        public readonly int $lines,
        public readonly float $avgLineLength,
        public readonly int $optimalThreads,
        public readonly string $path,
    ) {}

    public static function create(string $path, int $size, int $lines, int $optimalThreads)
    {
        return new self(
            size: $size,
            sizeMb: round($size / (1024 * 1024), 2),
            lines: $lines,
            avgLineLength: $lines > 0 ? round($size / $lines, 2) : 0,
            optimalThreads: $optimalThreads,
            path: $path,
        );
    }

    public function isLargeFile(): bool
    {
        return $this->sizeMb > 10;
    }

    public function shouldUseThreading(): bool
    {
        return $this->size > 512 * 1024; // 512KB
    }
}