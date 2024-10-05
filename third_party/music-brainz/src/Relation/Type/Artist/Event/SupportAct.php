<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Event;

use MusicBrainz\Relation\Type\Artist\Event;
use MusicBrainz\Value\Name;

/**
 * Links an event to (one of) its support act(s) (also known as opening acts or warm-up acts).
 *
 * @link https://musicbrainz.org/relationship/492a850e-97eb-306a-a85e-4b6d98527796
 */
class SupportAct extends Event
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('support act');
    }
}
