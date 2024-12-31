<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Series\WorkCataloguing;

use MusicBrainz\Relation\Type\Artist\Series\WorkCataloguing;
use MusicBrainz\Value\Name;

/**
 * This relationship is used to link a catalogue work series to a person who was involved in compiling it.
 *
 * @link https://musicbrainz.org/relationship/2a1b5f1d-b712-4791-8079-57f95ce197d7
 */
class Catalogued extends WorkCataloguing
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('catalogued');
    }
}
