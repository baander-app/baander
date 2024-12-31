<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup\RemixesAndCompilations;

use MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This links a remix release group to the source release group and is used to indicate that the release group includes remixed versions of all (or most of) the tracks in the other release group.
 *
 * @link https://musicbrainz.org/relationship/04e0449b-6fb0-48f6-8b9d-0bd41d9b8d76
 */
class Remix extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('remix');
    }
}
