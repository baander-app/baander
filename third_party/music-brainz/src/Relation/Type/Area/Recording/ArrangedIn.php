<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Recording;

use MusicBrainz\Relation\Type\Area\Recording;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the area it was arranged in. Use only when the place is unknown!
 *
 * @link https://musicbrainz.org/relationship/4f4aa317-c3c4-4001-ac23-fb8cf0bc543c
 */
class ArrangedIn extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('arranged in');
    }
}
