<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Genre;

use MusicBrainz\Relation\Type\Area\Genre;
use MusicBrainz\Value\Name;

/**
 * This relationship type links genres to the areas they originate from.
 *
 * @link https://musicbrainz.org/relationship/25ed73f8-a864-42cf-8b9c-68db198dbe0e
 */
class GenreOrigin extends Genre
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('genre origin');
    }
}
