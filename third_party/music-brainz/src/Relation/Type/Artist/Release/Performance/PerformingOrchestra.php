<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Performance;

use MusicBrainz\Relation\Type\Artist\Release\Performance;
use MusicBrainz\Value\Name;

/**
 * Indicates an orchestra that performed on this release.
 *
 * @link https://musicbrainz.org/relationship/23a2e2e7-81ca-4865-8d05-2243848a77bf
 */
class PerformingOrchestra extends Performance
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('performing orchestra');
    }
}
