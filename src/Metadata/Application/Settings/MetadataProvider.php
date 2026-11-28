<?php

declare(strict_types=1);

namespace App\Metadata\Application\Settings;

enum MetadataProvider: string
{
    case MusicBrainz = 'musicbrainz';
    case Discogs = 'discogs';
    case LastFm = 'lastfm';
    case Spotify = 'spotify';
    case TasteDive = 'tastedive';
    case CoverArtArchive = 'coverartarchive';
    case Tvdb = 'tvdb';
    case Tmdb = 'tmdb';
    case Local = 'local';

    public function settingKey(): string
    {
        return 'metadata.provider.' . $this->value . '.enabled';
    }

    public function label(): string
    {
        return match ($this) {
            self::LastFm => 'Last.fm',
            self::Tvdb => 'TheTVDB',
            self::Tmdb => 'TMDB',
            default => $this->name,
        };
    }

    public function attributionText(): string
    {
        return match ($this) {
            self::Local => 'Metadata and artwork from your library files.',
            self::Tmdb => 'This product uses the TMDB API but is not endorsed or certified by TMDB.',
            self::CoverArtArchive => 'Cover artwork from the Cover Art Archive.',
            default => 'Metadata provided by ' . $this->label() . '.',
        };
    }

    public function attributionUrl(): string
    {
        return match ($this) {
            self::MusicBrainz => 'https://musicbrainz.org/',
            self::Discogs => 'https://www.discogs.com/',
            self::LastFm => 'https://www.last.fm/',
            self::Spotify => 'https://open.spotify.com/',
            self::TasteDive => 'https://tastedive.com/',
            self::CoverArtArchive => 'https://coverartarchive.org/',
            self::Tvdb => 'https://thetvdb.com/',
            self::Tmdb => 'https://www.themoviedb.org/',
            self::Local => 'https://baander.app/',
        };
    }
}
