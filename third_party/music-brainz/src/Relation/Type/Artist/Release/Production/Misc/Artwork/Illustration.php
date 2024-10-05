<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc\Artwork;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc\Artwork;
use MusicBrainz\Value\Name;

/**
 * This indicates an artist who did illustration for the release.
 *
 * @link https://musicbrainz.org/relationship/a6029157-d96b-4dc3-9f73-f99f76423d11
 */
class Illustration extends Artwork
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('illustration');
    }
}
