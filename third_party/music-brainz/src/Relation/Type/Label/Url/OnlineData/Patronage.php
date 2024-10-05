<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OnlineData;

use MusicBrainz\Relation\Type\Label\Url\OnlineData;
use MusicBrainz\Value\Name;

/**
 * This links a label to a site where the label can receive donations/patronage, such as Flattr or PayPal.me.
 *
 * @link https://musicbrainz.org/relationship/e3d9c283-0146-4d91-9471-1b491a9c17ef
 */
class Patronage extends OnlineData
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('patronage');
    }
}
