<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url\GetTheMusic;

use MusicBrainz\Relation\Type\Release\Url\GetTheMusic;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link to a page where the release can be purchased for mail order.
 *
 * @link https://musicbrainz.org/relationship/3ee51e05-a06a-415e-b40c-b3f740dedfd7
 */
class PurchaseForMailOrder extends GetTheMusic
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('purchase for mail-order');
    }
}
