<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release;

use MusicBrainz\Relation\Type\Label\Release;
use MusicBrainz\Value\Name;

/**
 * This indicates the rights society associated with a release. The rights society is an organization which collects royalties on behalf of the artists.
 *
 * @link https://musicbrainz.org/relationship/06fc3d02-ae89-4566-ad49-624500d6beb7
 */
class RightsSociety extends Release
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('rights society');
    }
}
