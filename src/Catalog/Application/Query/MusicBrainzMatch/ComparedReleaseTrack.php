<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\ReleaseTrack;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A track of the chosen release as an album comparison pairs it with a local song. */
#[Exclude]
final readonly class ComparedReleaseTrack
{
    /**
     * @param int         $disc         the position of the track's medium in the release, from 1
     * @param int         $position     the track's position on its medium, from 1
     * @param string      $number       the track number as printed, such as "A2"
     * @param int|null    $lengthMs     null when MusicBrainz knows no length
     * @param string      $artistCredit the track's artist credit as printed
     * @param string|null $recordingId  the recording on the track; null when the editions the candidate stands for disagree on it
     */
    public function __construct(
        public int $disc,
        public int $position,
        public string $number,
        public string $title,
        public ?int $lengthMs,
        public string $artistCredit,
        public ?string $recordingId,
    ) {
    }

    public static function of(ReleaseTrack $track, ?string $recordingId): self
    {
        return new self(
            disc: $track->disc,
            position: $track->track->position,
            number: $track->track->number,
            title: $track->track->title,
            lengthMs: $track->track->lengthMs,
            artistCredit: $track->track->artistCredit->text(),
            recordingId: $recordingId,
        );
    }
}
