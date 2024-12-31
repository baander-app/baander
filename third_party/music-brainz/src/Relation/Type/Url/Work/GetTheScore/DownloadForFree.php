<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work\GetTheScore;

use MusicBrainz\Relation\Type\Url\Work\GetTheScore;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link to a page where the score / sheet music for this work can be legally downloaded for free.
 *
 * @link https://musicbrainz.org/relationship/0cc8527e-ea40-40dd-b144-3b7588e759bf
 */
class DownloadForFree extends GetTheScore
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
