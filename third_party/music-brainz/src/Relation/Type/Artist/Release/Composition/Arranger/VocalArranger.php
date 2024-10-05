<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Composition\Arranger;

use MusicBrainz\Relation\Type\Artist\Release\Composition\Arranger;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist who arranged a tune into a form suitable for performance. “Arrangement” is used as a catch-all term for all processes that turn a composition into a form that can be played by a specific type of ensemble.
 *
 * @link https://musicbrainz.org/relationship/d7d9128d-e676-4d8f-a353-f48a55a98501
 */
class VocalArranger extends Arranger
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('vocal arranger');
    }
}
