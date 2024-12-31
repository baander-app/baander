<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OnlineData\VideoChannel;

use MusicBrainz\Relation\Type\Place\Url\OnlineData\VideoChannel;
use MusicBrainz\Value\Name;

/**
 * This relationship type can be used to link a place to the equivalent entry in YouTube. URLs should follow the format http://www.youtube.com/user/<username>.
 *
 * @link https://musicbrainz.org/relationship/22ec436d-bb65-4c83-a268-0fdb0dbd8834
 */
class Youtube extends VideoChannel
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('youtube');
    }
}
