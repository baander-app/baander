<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Recording\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This is used to link to a page where the recording can be purchased for download.
 *
 * @link https://musicbrainz.org/relationship/92777657-504c-4acb-bd33-51a201bd57e1
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
