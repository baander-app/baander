<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * A key the song list can be ordered by.
 *
 * The values are the public `sort` query values of the song list endpoint.
 */
enum SongSortField: string
{
    case Title = 'title';
    case Artist = 'artist';
    case Album = 'album';
    case Year = 'year';
    case Added = 'added';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }
}
