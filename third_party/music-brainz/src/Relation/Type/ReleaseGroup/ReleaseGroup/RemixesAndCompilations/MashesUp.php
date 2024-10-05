<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup\RemixesAndCompilations;

use MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup\RemixesAndCompilations;
use MusicBrainz\Value\Name;

/**
 * This is used to indicate that the release group is a mash-up mash-up of two (or more) other release groups.
 *
 * @link https://musicbrainz.org/relationship/03786c2a-cd9d-4148-b3ea-35ea61de1283
 */
class MashesUp extends RemixesAndCompilations
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mashes up');
    }
}
