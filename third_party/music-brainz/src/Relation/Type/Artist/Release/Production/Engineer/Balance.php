<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This links a release to the balance engineer who engineered it.
 *
 * @link https://musicbrainz.org/relationship/97169e5e-c978-486e-a5ea-da353ca9ea42
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
