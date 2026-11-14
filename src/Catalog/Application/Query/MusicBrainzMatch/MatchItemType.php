<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Shared\Application\Exception\InvalidInputException;

/**
 * The kind of library item an admin matches with MusicBrainz, and what it matches: an album
 * matches a release (one edition), a song a recording, and an artist an artist.
 */
enum MatchItemType: string
{
    case Album = 'album';
    case Song = 'song';
    case Artist = 'artist';

    /** How the item names itself in messages, such as `Album "…" not found.` */
    public function label(): string
    {
        return match ($this) {
            self::Album => 'Album',
            self::Song => 'Song',
            self::Artist => 'Artist',
        };
    }

    /** What a candidate of the type is in MusicBrainz: a release, a recording or an artist. */
    public function candidateNoun(): string
    {
        return match ($this) {
            self::Album => 'release',
            self::Song => 'recording',
            self::Artist => 'artist',
        };
    }

    /**
     * The type an admin named, as the match routes and commands take it.
     *
     * @throws InvalidInputException when the value names no type
     */
    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidInputException(sprintf('The type must be %s.', self::names()));
    }

    /** The types in words: "album, song or artist". */
    private static function names(): string
    {
        $names = array_column(self::cases(), 'value');
        $last = array_pop($names);

        return implode(', ', $names) . ' or ' . $last;
    }
}
