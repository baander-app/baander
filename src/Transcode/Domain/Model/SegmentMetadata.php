<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Model;

final readonly class SegmentMetadata
{
    public function __construct(
        public int $index,
        public string $path,
        public int $size,
        public float $duration,
    ) {
    }
}
