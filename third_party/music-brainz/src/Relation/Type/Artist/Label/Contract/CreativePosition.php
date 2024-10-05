<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Label\Contract;

use MusicBrainz\Relation\Type\Artist\Label\Contract;
use MusicBrainz\Value\Name;

/**
 * This indicates that an artist was officially employed by a label in a creative position, such as photographer or graphic designer.
 *
 * @link https://musicbrainz.org/relationship/85d1947c-1986-42f0-947c-80aab72a548f
 */
class CreativePosition extends Contract
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('creative position');
    }
}
