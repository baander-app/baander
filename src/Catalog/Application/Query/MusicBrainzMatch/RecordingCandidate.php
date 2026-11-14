<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecording;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A MusicBrainz recording a song may match, with the releases it appears on. */
#[Exclude]
final readonly class RecordingCandidate
{
    /**
     * @param string                          $id           the recording's MusicBrainz ID, which CompareMatchQuery takes
     * @param int<0, 100>                     $score        how well the recording fits the song, by title, artist and length
     * @param string                          $artistCredit the recording's artist credit as printed, such as "Post Malone feat. Luke Combs"
     * @param int|null                        $lengthMs     null when MusicBrainz does not know the length
     * @param list<RecordingCandidateRelease> $releases     at most 25: MusicBrainz lists no more
     */
    public function __construct(
        public string $id,
        public int $score,
        public string $title,
        public string $artistCredit,
        public ?int $lengthMs,
        public ?string $disambiguation,
        public array $releases,
    ) {
    }

    /** @param int<0, 100> $score */
    public static function of(MusicBrainzRecording $recording, int $score): self
    {
        return new self(
            id: $recording->id,
            score: $score,
            title: $recording->title,
            artistCredit: $recording->artistCredit->text(),
            lengthMs: $recording->lengthMs,
            disambiguation: $recording->disambiguation,
            releases: array_map(RecordingCandidateRelease::of(...), $recording->releases),
        );
    }
}
