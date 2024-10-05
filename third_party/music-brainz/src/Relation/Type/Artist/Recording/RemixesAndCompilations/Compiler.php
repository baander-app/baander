<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Artist\Recording\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This indicates the person who selected the tracks and the sequence for a compilation. This applies to one long recording which contains multiple songs, one after the other. If the tracks are pitched or blended into each other, it is more appropriate to credit this person as a DJ-mixer.
 *
 * @link https://musicbrainz.org/relationship/35ba2b3b-aaeb-4db1-bc5f-f42154e785d8
 */
class Compiler extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('compiler');
    }
}
