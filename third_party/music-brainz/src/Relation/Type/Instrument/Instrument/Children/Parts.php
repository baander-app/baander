<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Instrument\Instrument\Children;

use MusicBrainz\Relation\Type\Instrument\Instrument\Children;
use MusicBrainz\Value\Name;

/**
 * This indicates that an instrument (often an ensemble or family) consists of two or more other instruments.
 *
 * @link https://musicbrainz.org/relationship/5ee4568f-d8bd-321d-9426-0ff6819ae6b5
 */
class Parts extends Children
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('parts');
    }
}
