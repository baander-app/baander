<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Instrument;

use MusicBrainz\Relation\Type\Genre\Instrument;
use MusicBrainz\Value\Name;

/**
 * This relationship type links instruments to genres they are commonly used in.
 *
 * @link https://musicbrainz.org/relationship/0b4d32c8-bdba-4842-a6b5-35b2ca2f4f11
 */
class AssociatedInstrument extends Instrument
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('associated instrument');
    }
}
