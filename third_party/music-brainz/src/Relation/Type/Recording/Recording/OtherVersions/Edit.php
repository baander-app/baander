<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Recording\OtherVersions;

use MusicBrainz\Relation\Type\Recording\Recording\OtherVersions;
use MusicBrainz\Value\Name;

/**
 * This links an edit to its original recording. An "edit", for this relationship, can be a radio edit (which involves streamlining a longer track to around the 3 minute mark in order to make it suitable for radio play), or a shortened, censored, or otherwise edited version of the same material. The person who edited the recording can be linked using the editor relationship type.
 *
 * @link https://musicbrainz.org/relationship/ce01b3ac-dd47-4702-9302-085344f96e84
 */
class Edit extends OtherVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('edit');
    }
}
