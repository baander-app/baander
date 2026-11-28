<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

enum PersonIdentifierNamespace: string
{
    case TmdbPerson = 'tmdb_person';
    case TvdbPerson = 'tvdb_person';
    case MusicBrainzArtist = 'musicbrainz_artist';
    case AppleMusicArtist = 'apple_music_artist';
    case ImdbName = 'imdb_name';

    public function normalize(string $value): ?string
    {
        $value = trim($value);

        return match ($this) {
            self::TmdbPerson, self::TvdbPerson => preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 && (int) $value <= 2147483647 ? $value : null,
            self::AppleMusicArtist => preg_match('/^[1-9][0-9]{0,30}$/D', $value) === 1 ? $value : null,
            self::MusicBrainzArtist => MusicbrainzId::tryFromString($value)?->toString(),
            self::ImdbName => preg_match('/^nm[0-9]{7,20}$/Di', $value) === 1 ? strtolower($value) : null,
        };
    }
}
