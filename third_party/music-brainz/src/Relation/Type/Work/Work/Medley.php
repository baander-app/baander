<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work\Work;

use MusicBrainz\Relation\Type\Work\Work;
use MusicBrainz\Value\Name;

/**
 * This is used to indicate that a work is a medley of several other songs. This means that the original songs were rearranged to create a new work in the form of a medley. See arranger for crediting the person who arranges songs into a medley.
 *
 * @link https://musicbrainz.org/relationship/c1dca2cd-194c-36dd-93f8-6a359167e992
 */
class Medley extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('medley');
    }
}
