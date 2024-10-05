<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Misc;

use MusicBrainz\Relation\Type\Artist\Work\Misc;
use MusicBrainz\Value\Name;

/**
 * This indicates the work that inspired this artist’s name.
 *
 * @link https://musicbrainz.org/relationship/535fdfed-3bca-40ad-966b-e67be7882d09
 */
class NamedAfter extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('named after');
    }
}
