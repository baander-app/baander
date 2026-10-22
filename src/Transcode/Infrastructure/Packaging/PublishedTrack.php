<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Packaging;

use App\Transcode\Application\DTO\MediaTrack;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class PublishedTrack
{
    /**
     * @param list<PublishedSegment> $segments
     * @param array<string,string> $representationAttributes
     * @param array<string,string> $adaptationAttributes
     */
    public function __construct(
        public MediaTrack $kind,
        public string $representationId,
        public string $codec,
        public int $timescale,
        public int $presentationTimeOffset,
        public string $initFile,
        public array $segments,
        public array $representationAttributes,
        public array $adaptationAttributes,
    ) {}

    public function covers(float $position): bool
    {
        $ticks = $position * $this->timescale + $this->presentationTimeOffset;
        foreach ($this->segments as $segment) {
            if ($ticks >= $segment->start && $ticks < $segment->start + $segment->duration) {
                return true;
            }
        }
        return false;
    }

    public function end(): float
    {
        $last = $this->segments[array_key_last($this->segments)] ?? null;
        return $last === null ? 0.0 : ($last->start + $last->duration - $this->presentationTimeOffset) / $this->timescale;
    }
}
