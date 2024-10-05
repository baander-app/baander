<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Series;

use MusicBrainz\Relation\Type\Label\Series;
use MusicBrainz\Value\Name;

/**
 * This allows linking a series to the label who publishes it.
 *
 * @link https://musicbrainz.org/relationship/1cd0342c-69a1-4f97-8471-46748f8ecde1
 */
class PublishesSeries extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('publishes series');
    }
}
