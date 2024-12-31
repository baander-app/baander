<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work;

use MusicBrainz\Relation\Type\Url\Work;
use MusicBrainz\Value\Name;

/**
 * This links a work to a license under which it is available.
 *
 * @link https://musicbrainz.org/relationship/770ea9f4-cba0-4194-b77f-fe2740055e34
 */
class License extends Work
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
