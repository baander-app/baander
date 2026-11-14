<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/**
 * A looked-up release (one edition of an album) with its media, tracks, labels, artist
 * credits, release group and genres.
 */
final readonly class MusicBrainzRelease
{
    /**
     * @param string $id the release's MusicBrainz ID
     * @param MusicBrainzArtistCredit $artistCredit the release's own credit: the album artist
     * @param string|null $date the release date (YYYY, YYYY-MM or YYYY-MM-DD)
     * @param string|null $country an ISO 3166-1 code, or "XW" for worldwide
     * @param string|null $status such as "Official" or "Promotion"
     * @param list<MusicBrainzLabel> $labels
     * @param list<MusicBrainzMedium> $media in disc order
     * @param MusicBrainzReleaseGroup|null $releaseGroup the album all editions share, with its genres
     * @param list<string> $genres the release's own genres, most votes first
     * @param bool $hasFrontCover whether the Cover Art Archive holds a front image for this release
     */
    public function __construct(
        public string $id,
        public string $title,
        public MusicBrainzArtistCredit $artistCredit,
        public ?string $date,
        public ?string $country,
        public ?string $status,
        public ?string $disambiguation,
        public ?string $barcode,
        public array $labels,
        public array $media,
        public ?MusicBrainzReleaseGroup $releaseGroup,
        public array $genres,
        public bool $hasFrontCover,
    ) {
    }

    /** The tracks on all media together. */
    public function trackCount(): int
    {
        return count($this->tracks());
    }

    /** @return list<MusicBrainzTrack> the tracks of every medium, in disc order and then track order */
    public function tracks(): array
    {
        return array_merge(...array_column($this->media, 'tracks'));
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
