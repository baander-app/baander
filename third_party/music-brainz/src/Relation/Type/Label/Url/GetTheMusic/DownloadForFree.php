<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Label\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * Indicates a webpage where you can download a label's releases for free.
 *
 * @link https://musicbrainz.org/relationship/46505eea-05d6-48cc-ad78-1f79abc556e1
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
