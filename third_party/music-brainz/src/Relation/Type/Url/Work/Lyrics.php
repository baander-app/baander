<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work;

use MusicBrainz\Relation\Type\Url\Work;
use MusicBrainz\Value\Name;

/**
 * This relationship describes a URL where lyrics for the work can be found. Only sites on the whitelist are permitted.
 *
 * @link https://musicbrainz.org/relationship/e38e65aa-75e0-42ba-ace0-072aeb91a538
 */
class Lyrics extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('lyrics');
    }
}
