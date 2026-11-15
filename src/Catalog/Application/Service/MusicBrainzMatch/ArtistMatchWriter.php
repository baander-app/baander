<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Catalog\Domain\Model\Artist;
use DateTimeImmutable;

/**
 * Writes the field rows of an artist comparison that a match decided on, an artist match and an
 * artist sync alike, and saves the artist. A field without a row stays as it is.
 */
final readonly class ArtistMatchWriter
{
    public function __construct(
        private ArtistPortInterface $artists,
    ) {
    }

    /** @param list<MatchFieldRow> $fields */
    public function write(Artist $artist, array $fields): void
    {
        $field = static fn (MatchField $name): ?MatchFieldRow => array_find($fields, static fn (MatchFieldRow $row): bool => $row->field === $name);
        $artist->updateMetadata(
            name: self::text($field(MatchField::Name)),
            country: self::text($field(MatchField::Country)),
            type: self::text($field(MatchField::Type)),
            lifeSpanBegin: self::date($field(MatchField::LifeSpanBegin)),
            lifeSpanEnd: self::date($field(MatchField::LifeSpanEnd)),
            disambiguation: self::text($field(MatchField::Disambiguation)),
            sortName: self::text($field(MatchField::SortName)),
        );
        $artist->updateExternalIds(mbid: self::text($field(MatchField::Mbid)));
        $this->artists->save($artist);
    }

    private static function text(?MatchFieldRow $row): ?string
    {
        return is_string($row?->proposed) ? $row->proposed : null;
    }

    private static function date(?MatchFieldRow $row): ?DateTimeImmutable
    {
        $text = self::text($row);

        return $text === null ? null : (DateTimeImmutable::createFromFormat('!Y-m-d', $text) ?: null);
    }
}
