<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Performance;

use MusicBrainz\Relation\Type\Artist\Recording\Performance;
use MusicBrainz\Value\Name;

/**
 * This indicates an artist who conducted an orchestra, band or choir on this recording.
 *
 * @link https://musicbrainz.org/relationship/234670ce-5f22-4fd0-921b-ef1662695c5d
 */
class Conductor extends Performance
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('conductor');
    }
}
