<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Arranger;

use MusicBrainz\Relation\Type\Artist\Recording\Arranger;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist who arranged a tune into a form suitable for performance. “Arrangement” is used as a catch-all term for all processes that turn a composition into a form that can be played by a specific type of ensemble.
 *
 * @link https://musicbrainz.org/relationship/8a2799e8-a7e2-41ce-a7da-b5f520687216
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
