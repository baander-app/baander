<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Composition;

use MusicBrainz\Relation\Type\Artist\Work\Composition;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist who arranged a tune into a form suitable for performance. 'Arrangement' is used as a catch-all term for all processes that turn a composition into a form that can be played by a specific type of ensemble.
 *
 * @link https://musicbrainz.org/relationship/d3fd781c-5894-47e2-8c12-86cc0e2c8d08
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
