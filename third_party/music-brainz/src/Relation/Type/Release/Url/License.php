<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url;

use MusicBrainz\Relation\Type\Release\Url;
use MusicBrainz\Value\Name;

/**
 * This links a release to a license under which it is available.
 *
 * @link https://musicbrainz.org/relationship/004bd0c3-8a45-4309-ba52-fa99f3aa3d50
 */
class License extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('license');
    }
}
