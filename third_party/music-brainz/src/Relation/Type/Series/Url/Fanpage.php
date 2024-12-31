<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url;

use MusicBrainz\Relation\Type\Series\Url;
use MusicBrainz\Value\Name;

/**
 * This links a series to a fan-created website.
 *
 * @link https://musicbrainz.org/relationship/34a39674-a192-4a06-b102-904bc557f095
 */
class Fanpage extends Url
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
