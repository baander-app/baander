<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Instrument\Label;

use MusicBrainz\Relation\Type\Instrument\Label;
use MusicBrainz\Value\Name;

/**
 * This relationship links an instrument to the company that invented or designed it.
 *
 * @link https://musicbrainz.org/relationship/9a1365db-5cce-4be6-9a6c-fbf566b26913
 */
class Invented extends Label
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
