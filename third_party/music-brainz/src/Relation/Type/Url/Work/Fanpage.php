<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work;

use MusicBrainz\Relation\Type\Url\Work;
use MusicBrainz\Value\Name;

/**
 * This links a work to a fan-created website.
 *
 * @link https://musicbrainz.org/relationship/174a04f8-a28e-41d0-a42a-7088887cd493
 */
class Fanpage extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('fanpage');
    }
}
