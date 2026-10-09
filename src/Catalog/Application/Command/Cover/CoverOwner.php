<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Cover;

/**
 * The kind of catalog item a cover belongs to. The value is the image's `imageable_type`.
 */
enum CoverOwner: string
{
    case Album = 'album';
    case Artist = 'artist';

    /** The owner's name as messages print it. */
    public function label(): string
    {
        return match ($this) {
            self::Album => 'Album',
            self::Artist => 'Artist',
        };
    }
}
