<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Recording;

use MusicBrainz\Relation\Type\Label\Recording;
use MusicBrainz\Value\Name;

/**
 * This relationship indicates the label is the phonographic copyright holder for this recording, usually indicated with a ℗ symbol.
 *
 * @link https://musicbrainz.org/relationship/fd841726-ba3c-47f7-af8e-6734ab6243ff
 */
class PhonographicCopyright extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('phonographic copyright');
    }
}
