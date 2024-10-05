<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This links an (fictional) artist to the person that voice acted it.
 *
 * @link https://musicbrainz.org/relationship/e259a3f5-ce8e-45c1-9ef7-90ff7d0c7589
 */
class VoiceActor extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('voice actor');
    }
}
