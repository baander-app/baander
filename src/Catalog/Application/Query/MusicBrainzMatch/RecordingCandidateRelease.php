<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRecordingRelease;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A release a recording candidate appears on. */
#[Exclude]
final readonly class RecordingCandidateRelease
{
    /**
     * @param string      $id         the release's MusicBrainz ID
     * @param string|null $date       the release date (YYYY, YYYY-MM or YYYY-MM-DD)
     * @param string|null $country    an ISO 3166-1 code, or "XW" for worldwide
     * @param string|null $status     such as "Official" or "Promotion"
     * @param int|null    $trackCount the tracks on the whole release, when MusicBrainz lists them
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $date,
        public ?string $country,
        public ?string $status,
        public ?int $trackCount,
    ) {
    }

    public static function of(MusicBrainzRecordingRelease $release): self
    {
        return new self($release->id, $release->title, $release->date, $release->country, $release->status, $release->trackCount);
    }
}
