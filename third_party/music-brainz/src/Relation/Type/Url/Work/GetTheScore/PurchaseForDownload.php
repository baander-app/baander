<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work\GetTheScore;

use MusicBrainz\Relation\Type\Url\Work\GetTheScore;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link to a page where the score / sheet music for this work can be legally purchased for download.
 *
 * @link https://musicbrainz.org/relationship/68ac61a1-6151-48bf-8596-ac3bcc3a41a2
 */
class PurchaseForDownload extends GetTheScore
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
