<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;

use MusicBrainz\Relation\Type\Label\Label\BusinessAssociation;
use MusicBrainz\Value\Name;

/**
 * This describes a situation where one label is reissuing, under its own name, (part of) another label's catalog. This can happen in at least three cases:
 *
 * @link https://musicbrainz.org/relationship/1a502d1c-2c30-4efa-8cd7-39af664e3af8
 */
class LabelReissue extends BusinessAssociation
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('label reissue');
    }
}
