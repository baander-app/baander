<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Recording\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This links a recording to a page where it can be legally downloaded for free.
 *
 * @link https://musicbrainz.org/relationship/45d0cbc5-d65b-4e77-bdfd-8a75207cb5c5
 */
class DownloadForFree extends GetTheMusic
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('download for free');
    }
}
