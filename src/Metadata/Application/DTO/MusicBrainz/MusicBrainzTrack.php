<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** A track of a looked-up release: one recording at one position on one medium. */
final readonly class MusicBrainzTrack
{
    /**
     * @param string $id the track's MusicBrainz ID on this release
     * @param int $position the track's 1-based position on its medium
     * @param string $number the track number as printed, free text such as "A2"
     * @param int|null $lengthMs the length on this release, else the recording's length; null when MusicBrainz knows neither
     * @param string $recordingId the MusicBrainz ID of the recording on this track
     * @param MusicBrainzArtistCredit $artistCredit the credit printed for this track, else the recording's credit
     */
    public function __construct(
        public string $id,
        public int $position,
        public string $number,
        public string $title,
        public ?int $lengthMs,
        public string $recordingId,
        public MusicBrainzArtistCredit $artistCredit,
    ) {
    }
}
