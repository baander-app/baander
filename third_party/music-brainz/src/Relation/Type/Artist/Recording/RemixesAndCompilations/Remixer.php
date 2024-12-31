<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Artist\Recording\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This links a recording to the person who remixed it by taking one or more other tracks, substantially altering them and mixing them together with other material. Note that this includes the artist who created a mash-up or used samples as well.
 *
 * @link https://musicbrainz.org/relationship/7950be4d-13a3-48e7-906b-5af562e39544
 */
class Remixer extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('remixer');
    }
}
