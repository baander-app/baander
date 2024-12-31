<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Work\Work\OtherVersion\Arrangement;

use MusicBrainz\Relation\Type\Work\Work\OtherVersion\Arrangement;
use MusicBrainz\Value\Name;

/**
 * This links two works where one work is an orchestration of the other.
 *
 * @link https://musicbrainz.org/relationship/dd372cb2-5f4d-4dcd-868e-7564782f460b
 */
class Orchestration extends Arrangement
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('orchestration');
    }
}
