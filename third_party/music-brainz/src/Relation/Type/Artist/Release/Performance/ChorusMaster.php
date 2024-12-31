<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Performance;

use MusicBrainz\Relation\Type\Artist\Release\Performance;
use MusicBrainz\Value\Name;

/**
 * This indicates the chorus master of a choir which performed on this release.
 *
 * @link https://musicbrainz.org/relationship/b9129850-73ec-4af5-803c-1c12b97e25d2
 */
class ChorusMaster extends Performance
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('chorus master');
    }
}
