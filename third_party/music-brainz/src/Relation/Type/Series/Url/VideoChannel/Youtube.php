<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url\VideoChannel;

use MusicBrainz\Relation\Type\Series\Url\VideoChannel;
use MusicBrainz\Value\Name;

/**
 * This relationship type can be used to link a series to the equivalent entry in YouTube. URLs should follow the format http://www.youtube.com/user/<username>.
 *
 * @link https://musicbrainz.org/relationship/f23802a4-36be-3751-8e4d-93422e08b3e8
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
