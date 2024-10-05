<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\ReleaseGroup;

use MusicBrainz\Relation\Type\Artist\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * This indicates the release group that inspired this artist’s name.
 *
 * @link https://musicbrainz.org/relationship/cee8e577-6fa6-4d77-abc0-35bce13c570e
 */
class NamedAfter extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('named after');
    }
}
