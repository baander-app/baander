<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Performance;

use MusicBrainz\Relation\Type\Artist\Release\Performance;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist was an audio director for this release. This is the artist responsible for the creative realization of an audio project (such as an audio drama or audiobook), which is usually based on a written template and involves the performance of voice actors.
 *
 * @link https://musicbrainz.org/relationship/4e088178-ea6f-4194-a801-10b4f0f03154
 */
class AudioDirector extends Performance
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('audio director');
    }
}
