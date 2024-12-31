<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url\Production;

use MusicBrainz\Relation\Type\Release\Url\Production;
use MusicBrainz\Value\Name;

/**
 * This relationship type is deprecated and should not be used.
 *
 * @link https://musicbrainz.org/relationship/7387c5a2-9abe-4515-b667-9eb5ed4dd4ce
 */
class IMDBSamples extends Production
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('IMDB samples');
    }
}
