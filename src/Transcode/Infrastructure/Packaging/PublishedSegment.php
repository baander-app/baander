<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Packaging;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class PublishedSegment
{
    public function __construct(
        public int $ordinal,
        public int $nativeNumber,
        public int $start,
        public int $duration,
        public string $file,
    ) {}
}
