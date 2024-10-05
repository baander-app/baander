<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work;

use MusicBrainz\Relation\Type\Url\Work;
use MusicBrainz\Value\Name;

/**
 * This link points to a page for a particular work within a work list for an artist.
 *
 * @link https://musicbrainz.org/relationship/9900c8c5-9844-4f10-8403-23aabafd913c
 */
class WorkListEntry extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('work list entry');
    }
}
