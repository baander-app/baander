<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This credits a person who was responsible for booking the studio or performance venue where the recording was recorded.
 *
 * @link https://musicbrainz.org/relationship/b1edc6f6-283d-4e32-b625-b96cfb192056
 */
class Booking extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('booking');
    }
}
