<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Label\Contract;

use MusicBrainz\Relation\Type\Artist\Label\Contract;
use MusicBrainz\Value\Name;

/**
 * This indicates that an artist was officially employed by a label in an artists and repertoire (A&R) position.
 *
 * @link https://musicbrainz.org/relationship/8f60b62e-5755-4842-866a-269d1255a235
 */
class ArtistsAndRepertoirePosition extends Contract
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('artists and repertoire position');
    }
}
