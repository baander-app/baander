<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Misc;

use MusicBrainz\Relation\Type\Artist\Work\Misc;
use MusicBrainz\Value\Name;

/**
 * Indicates the publisher of this work. This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/a442b140-830b-30b0-a4aa-2e36f098b6aa
 */
class Publishing extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('publishing');
    }
}
