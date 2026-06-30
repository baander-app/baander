<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

/**
 * Represents the role an artist has on a release or track.
 *
 * Backed by a string for database storage. Each case has a human-readable
 * label suitable for display in the UI.
 */
enum ArtistRole: string
{
    case Primary = 'primary';
    case Featured = 'featured';
    case Producer = 'producer';
    case Composer = 'composer';
    case Conductor = 'conductor';
    case Remixer = 'remixer';
    case Djmix = 'djmix';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary Artist',
            self::Featured => 'Featured Artist',
            self::Producer => 'Producer',
            self::Composer => 'Composer',
            self::Conductor => 'Conductor',
            self::Remixer => 'Remixer',
            self::Djmix => 'DJ Mix',
            self::Other => 'Other',
        };
    }
}
