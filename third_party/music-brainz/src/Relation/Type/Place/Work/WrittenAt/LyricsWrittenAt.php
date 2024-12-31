<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Work\WrittenAt;

use MusicBrainz\Relation\Type\Place\Work\WrittenAt;
use MusicBrainz\Value\Name;

/**
 * This links a work with the place its lyrics were written at.
 *
 * @link https://musicbrainz.org/relationship/dfd87666-da9b-43ac-b45e-7ad01b355746
 */
class LyricsWrittenAt extends WrittenAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('lyrics written at');
    }
}
