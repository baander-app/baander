<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Recording\OtherVersions;

use MusicBrainz\Relation\Type\Recording\Recording\OtherVersions;
use MusicBrainz\Value\Name;

/**
 * This relationship type is deprecated and should not be used.
 *
 * @link https://musicbrainz.org/relationship/b984b8d1-76f9-43d7-aa3e-0b3a46999dea
 */
class Remaster extends OtherVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('remaster');
    }
}
