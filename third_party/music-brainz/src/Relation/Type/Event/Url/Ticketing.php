<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Url;

use MusicBrainz\Relation\Type\Event\Url;
use MusicBrainz\Value\Name;

/**
 * This links an event to a site where tickets can be purchased for it.
 *
 * @link https://musicbrainz.org/relationship/bf0f91b9-d97e-4a7b-9114-f1db1e0b61de
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
