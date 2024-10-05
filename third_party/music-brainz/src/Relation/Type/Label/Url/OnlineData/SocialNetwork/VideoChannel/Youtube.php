<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData\SocialNetwork\VideoChannel;

use MusicBrainz\Relation\Type\Label\Url\OnlineData\SocialNetwork\VideoChannel;
use MusicBrainz\Value\Name;

/**
 * This links a label to the equivalent entry at YouTube.
 *
 * @link https://musicbrainz.org/relationship/d9c71059-ba9d-4135-b909-481d12cf84e3
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
