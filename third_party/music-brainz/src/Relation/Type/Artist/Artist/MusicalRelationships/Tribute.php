<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This relationship specifies that an artist is a tribute to another specific artist/band; that is, it primarily performs covers of that artist. They may also be referred to as cover bands. Some tribute artists may name themselves, dress, and/or act similarly to the artists they pay tribute to.
 *
 * @link https://musicbrainz.org/relationship/a6f62641-2f58-470e-b02b-88d7b984dc9f
 */
class Tribute extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('tribute');
    }
}
