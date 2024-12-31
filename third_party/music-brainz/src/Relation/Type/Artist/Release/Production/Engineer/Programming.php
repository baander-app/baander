<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This links a release to the artist who did the programming for electronic instruments used on the release. In the most cases, the 'electronic instrument' is either a synthesizer or a drum machine.
 *
 * @link https://musicbrainz.org/relationship/617063ad-dbb5-4877-9ba0-ba2b9198d5a7
 */
class Programming extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('programming');
    }
}
