<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Work\WrittenIn;

use MusicBrainz\Relation\Type\Area\Work\WrittenIn;
use MusicBrainz\Value\Name;

/**
 * This links a work with the area its lyrics were written in.
 *
 * @link https://musicbrainz.org/relationship/43ba113b-968f-4fa9-93b2-73e60f954c90
 */
class LyricsWrittenIn extends WrittenIn
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('lyrics written in');
    }
}
