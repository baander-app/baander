<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Arranger;

use MusicBrainz\Relation\Type\Artist\Recording\Arranger;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist who arranged a tune into a form suitable for performance. “Arrangement” is used as a catch-all term for all processes that turn a composition into a form that can be played by a specific type of ensemble.
 *
 * @link https://musicbrainz.org/relationship/4820daa1-98d6-4f8b-aa4b-6895c5b79b27
 */
class InstrumentArranger extends Arranger
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('instrument arranger');
    }
}
