<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url;

use MusicBrainz\Relation\Type\Series\Url;
use MusicBrainz\Value\Name;

/**
 * This links a series to a site where tickets can be purchased for events in it.
 *
 * @link https://musicbrainz.org/relationship/bb8ad711-4667-4395-9bfa-453b1299a79b
 */
class Ticketing extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('ticketing');
    }
}
