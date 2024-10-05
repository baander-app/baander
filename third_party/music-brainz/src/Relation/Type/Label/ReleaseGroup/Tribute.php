<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\ReleaseGroup;

use MusicBrainz\Relation\Type\Label\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * This links a release group to a label, to indicate that it is a tribute album. Tribute albums often have a title in the form "A Tribute to Label" or "A Tribute to Artists from Label", and they consist of covers of songs put out by the label, played by other (sometimes very unknown) artists, to honor the label. Often they are various artist compilations, but a single artist can perform the entire tribute album.
 *
 * @link https://musicbrainz.org/relationship/a55095e6-5fd1-4a92-963e-75078a3c28b7
 */
class Tribute extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('tribute');
    }
}
