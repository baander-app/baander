<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Work;

use MusicBrainz\Relation\Type\Place\Work;
use MusicBrainz\Value\Name;

/**
 * Indicates the place where the work had its first performance
 *
 * @link https://musicbrainz.org/relationship/a4d2a7cb-365b-4b90-b52f-29469edf8ac0
 */
class Premiere extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('premiere');
    }
}
