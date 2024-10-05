<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production;

use MusicBrainz\Relation\Type\Artist\Recording\Production;
use MusicBrainz\Value\Name;

/**
 * This indicates an artist who is responsible for the creative and practical day-to-day aspects involved with making a musical recording.
 *
 * @link https://musicbrainz.org/relationship/5c0ceac3-feb4-41f0-868d-dc06f6e27fc0
 */
class Producer extends Production
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('producer');
    }
}
