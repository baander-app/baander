<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url;

use MusicBrainz\Relation\Type\Label\Url;
use MusicBrainz\Value\Name;

/**
 * Indicates the official homepage for a label.
 *
 * @link https://musicbrainz.org/relationship/fe108f43-acb9-4ad1-8be3-57e6ec5b17b6
 */
class OfficialSite extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('official site');
    }
}
