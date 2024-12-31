<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Instrument;

use MusicBrainz\Relation\Type\Artist\Instrument;
use MusicBrainz\Value\Name;

/**
 * This relationship links an instrument to the person(s) who invented or designed it.
 *
 * @link https://musicbrainz.org/relationship/87bfa63d-c91f-4bf2-9051-5103f7d181dd
 */
class Invented extends Instrument
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('invented');
    }
}
