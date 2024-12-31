<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This links a recording to the balance engineer who engineered it.
 *
 * @link https://musicbrainz.org/relationship/0748fa55-56b5-4ad5-8ce8-15b97f82a0c2
 */
class Balance extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('balance');
    }
}
