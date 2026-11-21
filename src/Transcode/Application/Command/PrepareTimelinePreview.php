<?php

declare(strict_types=1);

namespace App\Transcode\Application\Command;

final readonly class PrepareTimelinePreview
{
    public function __construct(
        public string $path,
        public string $identity,
        public float $duration,
        public float $origin,
        public string $sourceHash,
        public int $videoStreamIndex,
    ) {
    }
}
