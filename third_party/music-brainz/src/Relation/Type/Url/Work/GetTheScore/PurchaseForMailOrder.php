<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work\GetTheScore;

use MusicBrainz\Relation\Type\Url\Work\GetTheScore;
use MusicBrainz\Value\Name;

/**
 * This relationship type is used to link to a page where the score / sheet music for this work can be legally purchased for mail order.
 *
 * @link https://musicbrainz.org/relationship/26b5d3aa-7d05-4121-a9eb-bf8a9aeb6e28
 */
class PurchaseForMailOrder extends GetTheScore
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
