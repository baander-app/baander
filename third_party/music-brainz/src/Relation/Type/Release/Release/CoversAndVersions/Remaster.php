<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Release\CoversAndVersions;

use MusicBrainz\Relation\Type\Release\Release\CoversAndVersions;
use MusicBrainz\Value\Name;

/**
 * This links two releases, where one is a remaster of the other. This is usually done to improve the audio quality or to adjust for more modern playback equipment. The process generally doesn't involve changing the music in any artistically important way. It may, however, result in recordings that differ in length by a few seconds.
 *
 * @link https://musicbrainz.org/relationship/48e327b5-2d04-4518-93f1-fed5f0f0fa3c
 */
class Remaster extends CoversAndVersions
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('remaster');
    }
}
