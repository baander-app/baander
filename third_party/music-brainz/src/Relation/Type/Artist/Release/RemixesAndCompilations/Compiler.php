<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Artist\Release\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This indicates the person who selected the tracks and the sequence for a compilation. If the tracks are pitched or blended into each other, it is more appropriate to credit this person as a DJ-mixer.
 *
 * @link https://musicbrainz.org/relationship/2f81887a-8674-4d8b-bd48-8bfd4c6fa332
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
