<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData;

use MusicBrainz\Relation\Type\Label\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This links a label to a fan-created website.
 *
 * @link https://musicbrainz.org/relationship/6b91b233-a68c-4854-ba33-3b9ae27f86ae
 */
class Fanpage extends OnlineData
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
