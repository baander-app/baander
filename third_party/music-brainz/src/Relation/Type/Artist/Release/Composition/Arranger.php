<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Composition;

use MusicBrainz\Relation\Type\Artist\Release\Composition;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist who arranged a tune into a form suitable for performance. “Arrangement” is used as a catch-all term for all processes that turn a composition into a form that can be played by a specific type of ensemble.
 *
 * @link https://musicbrainz.org/relationship/34d5334e-a4c8-4b65-a5f8-bbcc9c81d13d
 */
class Arranger extends Composition
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('arranger');
    }
}
