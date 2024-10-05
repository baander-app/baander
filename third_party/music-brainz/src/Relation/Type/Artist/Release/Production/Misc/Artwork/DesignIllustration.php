<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc\Artwork;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc\Artwork;
use MusicBrainz\Value\Name;

/**
 * This relationship type is deprecated and should not be used.
 *
 * @link https://musicbrainz.org/relationship/307e95dd-88b5-419b-8223-b146d4a0d439
 */
class DesignIllustration extends Artwork
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('design/illustration');
    }
}
