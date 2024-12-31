<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Release;

use MusicBrainz\Relation\Type\Event\Release;
use MusicBrainz\Value\Name;

/**
 * Links a release to the event it was recorded at.
 *
 * @link https://musicbrainz.org/relationship/4dda6e40-14af-46bb-bb78-ea22f4a99dfa
 */
class RecordedAt extends Release
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('recorded at');
    }
}
