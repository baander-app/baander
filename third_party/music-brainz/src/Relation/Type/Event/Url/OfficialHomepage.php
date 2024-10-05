<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Url;

use MusicBrainz\Relation\Type\Event\Url;
use MusicBrainz\Value\Name;

/**
 * Indicates the official homepage for an event.
 *
 * @link https://musicbrainz.org/relationship/c26808b0-4e67-31a7-a587-913720dfb3f3
 */
class OfficialHomepage extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('official homepage');
    }
}
