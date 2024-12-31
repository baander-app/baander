<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url;

use MusicBrainz\Relation\Type\Label\Url;
use MusicBrainz\Value\Name;

/**
 * This relationship describes a URL where lyrics for the label (most often as a publisher) can be found. Only sites on the whitelist are permitted.
 *
 * @link https://musicbrainz.org/relationship/9eb3977f-2aa2-41dd-bbff-0cadda5ad484
 */
class Lyrics extends Url
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
