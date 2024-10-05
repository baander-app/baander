<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording;

use MusicBrainz\Relation\Type\Artist\Recording;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist who arranged a tune into a form suitable for performance. “Arrangement” is used as a catch-all term for all processes that turn a composition into a form that can be played by a specific type of ensemble.
 *
 * @link https://musicbrainz.org/relationship/22661fb8-cdb7-4f67-8385-b2a8be6c9f0d
 */
class Arranger extends Recording
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
