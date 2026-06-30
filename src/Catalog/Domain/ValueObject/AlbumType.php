<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

/**
 * Represents the type or format of a music release.
 *
 * Backed by a string for database storage. Each case has a human-readable
 * label suitable for display in the UI.
 */
enum AlbumType: string
{
    case Studio = 'studio';
    case Live = 'live';
    case Compilation = 'compilation';
    case Single = 'single';
    case Ep = 'ep';
    case Remix = 'remix';
    case Soundtrack = 'soundtrack';
    case SpokenWord = 'spoken_word';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Studio => 'Studio Album',
            self::Live => 'Live Album',
            self::Compilation => 'Compilation',
            self::Single => 'Single',
            self::Ep => 'EP',
            self::Remix => 'Remix',
            self::Soundtrack => 'Soundtrack',
            self::SpokenWord => 'Spoken Word',
            self::Other => 'Other',
        };
    }
}
