<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc\Artwork\Design;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc\Artwork\Design;
use MusicBrainz\Value\Name;

/**
 * This indicates an artist who did the graphic design for the release, arranging pieces of content into a coherent and aesthetically-pleasing sleeve design.
 *
 * @link https://musicbrainz.org/relationship/cf43b79e-3299-4b0c-9244-59ea06337107
 */
class GraphicDesign extends Design
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('graphic design');
    }
}
