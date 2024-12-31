<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Series;

use MusicBrainz\Relation\Type\Artist\Series;
use MusicBrainz\Value\Name;

/**
 * This indicates an artist (generally a person) was the founder of a series (mostly, but not always, an event series such as a festival).
 *
 * @link https://musicbrainz.org/relationship/bf846051-084c-4c49-b0af-17c61c428572
 */
class Founder extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('founder');
    }
}
