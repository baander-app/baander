<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Work;

use MusicBrainz\Relation\Type\Label\Work;
use MusicBrainz\Value\Name;

/**
 * This indicates the work is dedicated to a specific organization. This is most common for classical works, but also exists in other genres to a degree.
 *
 * @link https://musicbrainz.org/relationship/762b17eb-e511-4cc0-836e-a081831c1754
 */
class Dedication extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('dedication');
    }
}
