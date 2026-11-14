<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** A MusicBrainz recording (a song as performed) with the releases it appears on. */
final readonly class MusicBrainzRecording
{
    /**
     * @param string $id the recording's MusicBrainz ID
     * @param int $score MusicBrainz's text relevance from 0 to 100; 0 when not from a search
     * @param int|null $lengthMs null when MusicBrainz does not know the length
     * @param list<MusicBrainzRecordingRelease> $releases at most 25: MusicBrainz lists no more
     */
    public function __construct(
        public string $id,
        public string $title,
        public int $score,
        public ?int $lengthMs,
        public ?string $disambiguation,
        public MusicBrainzArtistCredit $artistCredit,
        public array $releases,
    ) {
    }
}
