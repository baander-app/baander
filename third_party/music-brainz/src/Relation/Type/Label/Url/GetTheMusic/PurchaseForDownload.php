<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Label\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This is used to link to a page where the label's releases can be purchased for download.
 *
 * @link https://musicbrainz.org/relationship/dc1a65f4-6458-4f3d-bbb1-57e58668d6e7
 */
class PurchaseForDownload extends GetTheMusic
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('purchase for download');
    }
}
