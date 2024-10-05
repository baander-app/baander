<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Instrument\Instrument\Children;

use MusicBrainz\Relation\Type\Instrument\Instrument\Children;
use MusicBrainz\Value\Name;

/**
 * This links an instrument to more specific subtypes of it.
 *
 * @link https://musicbrainz.org/relationship/40b2bd3f-1457-3ceb-810d-57f87f0f74f0
 */
class Subtype extends Children
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('subtype');
    }
}
