<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Recording\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Recording\Recording\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This is used to indicate that the recording is a mash-up of two (or more) other recordings.
 *
 * @link https://musicbrainz.org/relationship/579d0b4c-bf77-479d-aa59-a8af1f518958
 */
class MashesUp extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mashes up');
    }
}
