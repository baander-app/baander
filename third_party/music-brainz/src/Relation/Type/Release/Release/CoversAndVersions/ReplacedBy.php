<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Release\CoversAndVersions;

use MusicBrainz\Relation\Type\Release\Release\CoversAndVersions;
use MusicBrainz\Value\Name;

/**
 * This links a release that was withdrawn (usually because of having some defect, but sometimes just to change the artist credits) to a new release put out to replaced it.
 *
 * @link https://musicbrainz.org/relationship/7918eb7f-bfb0-4245-91fd-3a0e86e13841
 */
class ReplacedBy extends CoversAndVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('replaced by');
    }
}
