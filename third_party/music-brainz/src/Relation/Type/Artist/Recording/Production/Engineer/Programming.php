<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This links a recording to the artist who did the programming for electronic instruments used on the recording. In the most cases, the 'electronic instrument' is either a synthesizer or a drum machine.
 *
 * @link https://musicbrainz.org/relationship/36c50022-44e0-488d-994b-33f11d20301e
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
