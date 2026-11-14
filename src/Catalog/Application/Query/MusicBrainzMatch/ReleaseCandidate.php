<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzLabel;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A MusicBrainz release (one edition of an album) an album may match, with what tells it from other editions. */
#[Exclude]
final readonly class ReleaseCandidate
{
    /**
     * @param string       $id             the release's MusicBrainz ID, which CompareMatchQuery takes
     * @param int<0, 100>  $score          how well the release fits the album, by title, artist, track count and track lengths
     * @param string       $artistCredit   the release's artist credit as printed, such as "Post Malone"
     * @param string|null  $date           the release date (YYYY, YYYY-MM or YYYY-MM-DD)
     * @param string|null  $country        an ISO 3166-1 code, or "XW" for worldwide
     * @param list<string> $labels         the label names, each once
     * @param list<string> $catalogNumbers the catalogue numbers on those labels, each once
     * @param list<string> $formats        the media formats, such as "CD" or "Digital Media", each once
     * @param bool         $hasFrontCover  whether the Cover Art Archive holds a front cover for the release
     */
    public function __construct(
        public string $id,
        public int $score,
        public string $title,
        public string $artistCredit,
        public ?string $date,
        public ?string $country,
        public ?string $status,
        public ?string $disambiguation,
        public ?string $barcode,
        public array $labels,
        public array $catalogNumbers,
        public array $formats,
        public int $trackCount,
        public int $mediumCount,
        public ?string $releaseGroupId,
        public ?string $releaseGroupType,
        public bool $hasFrontCover,
    ) {
    }

    /** @param int<0, 100> $score */
    public static function of(MusicBrainzRelease $release, int $score): self
    {
        return new self(
            id: $release->id,
            score: $score,
            title: $release->title,
            artistCredit: $release->artistCredit->text(),
            date: $release->date,
            country: $release->country,
            status: $release->status,
            disambiguation: $release->disambiguation,
            barcode: $release->barcode,
            labels: self::distinct(array_map(static fn (MusicBrainzLabel $label): ?string => $label->name, $release->labels)),
            catalogNumbers: self::distinct(array_map(static fn (MusicBrainzLabel $label): ?string => $label->catalogNumber, $release->labels)),
            formats: $release->formats(),
            trackCount: $release->trackCount(),
            mediumCount: count($release->media),
            releaseGroupId: $release->releaseGroup?->id,
            releaseGroupType: $release->releaseGroup?->primaryType,
            hasFrontCover: $release->hasFrontCover,
        );
    }

    /**
     * @param list<string|null> $values
     *
     * @return list<string> the non-blank values, each once, in order
     */
    private static function distinct(array $values): array
    {
        return array_filter($values, static fn (?string $value): bool => $value !== null && trim($value) !== '')
            |> array_unique(...)
            |> array_values(...);
    }
}
