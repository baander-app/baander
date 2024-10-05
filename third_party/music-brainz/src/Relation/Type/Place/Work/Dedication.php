<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Work;

use MusicBrainz\Relation\Type\Place\Work;
use MusicBrainz\Value\Name;

/**
 * This indicates the work is dedicated to a specific place (such an educational institution). This is most common for classical works, but also exists in other genres to a degree.
 *
 * @link https://musicbrainz.org/relationship/4121e462-a6bd-4e33-8fe7-0f9aee69f5e9
 */
class Dedication extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('dedication');
    }
}
