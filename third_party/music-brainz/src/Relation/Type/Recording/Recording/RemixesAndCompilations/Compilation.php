<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Recording\RemixesAndCompilations;

use MusicBrainz\Relation\Type\Recording\Recording\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This indicates that a recording is a compilation of several other recordings. This applies to one long recording that contains multiple songs, one after the other, in which the audio material of the original recordings has not been altered. If the tracks are pitched or blended into each other, the DJ-mix relationship type may be more appropriate.
 *
 * @link https://musicbrainz.org/relationship/1b6311e8-5f81-43b7-8c55-4bbae71ec00c
 */
class Compilation extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('compilation');
    }
}
