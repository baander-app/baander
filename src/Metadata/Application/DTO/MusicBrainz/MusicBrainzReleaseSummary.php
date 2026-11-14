<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** A release (one edition of an album) as a MusicBrainz release search returns it, without tracks. */
final readonly class MusicBrainzReleaseSummary
{
    /**
     * @param string $id the release's MusicBrainz ID
     * @param int $score MusicBrainz's text relevance from 0 to 100; it orders hits but cannot tell editions apart
     * @param string|null $date the release date (YYYY, YYYY-MM or YYYY-MM-DD)
     * @param string|null $country an ISO 3166-1 code, or "XW" for worldwide
     * @param string|null $status such as "Official" or "Promotion"
     * @param list<MusicBrainzLabel> $labels
     * @param list<MusicBrainzMediumSummary> $media in disc order
     * @param int $trackCount the tracks on all media together
     */
    public function __construct(
        public string $id,
        public string $title,
        public int $score,
        public MusicBrainzArtistCredit $artistCredit,
        public ?string $date,
        public ?string $country,
        public ?string $status,
        public ?string $disambiguation,
        public ?string $barcode,
        public array $labels,
        public array $media,
        public int $trackCount,
        public ?MusicBrainzReleaseGroup $releaseGroup,
    ) {
    }

    public function mediumCount(): int
    {
        return count($this->media);
    }

    /** @return list<string> the formats of the media, such as "CD" or "Digital Media", each once */
    public function formats(): array
    {
        return array_column($this->media, 'format')
            |> array_filter(...)
            |> array_unique(...)
            |> array_values(...);
    }
}
