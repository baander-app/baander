<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Packaging;

use App\Transcode\Application\DTO\MediaTrack;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class PublishedPresentation
{
    /**
     * @param array<string,string> $mpdAttributes
     * @param array<string,string> $periodAttributes
     * @param array<string,PublishedTrack> $tracks
     */
    public function __construct(
        public bool $complete,
        public array $mpdAttributes,
        public array $periodAttributes,
        public array $tracks,
    ) {}

    public function track(MediaTrack $kind): ?PublishedTrack
    {
        return $this->tracks[$kind->value] ?? null;
    }

    public function covers(float $position, bool $hasAudio, ?float $sourceDuration = null): bool
    {
        $atEnd = $this->complete && $sourceDuration !== null && $sourceDuration > 0.0
            && abs($position - $sourceDuration) < 0.000001;
        foreach ([MediaTrack::Video, ...($hasAudio ? [MediaTrack::Audio] : [])] as $kind) {
            $track = $this->track($kind);
            if ($track === null) {
                return false;
            }
            if ($atEnd && abs($track->end() - $sourceDuration) > 0.1) {
                return false;
            }
            $target = $atEnd ? max(0.0, $track->end() - 1 / $track->timescale) : $position;
            if (!$track->covers($target)) {
                return false;
            }
        }
        return true;
    }
}
