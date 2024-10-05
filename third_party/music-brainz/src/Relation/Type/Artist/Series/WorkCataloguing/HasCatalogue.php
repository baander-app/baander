<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Series\WorkCataloguing;

use MusicBrainz\Relation\Type\Artist\Series\WorkCataloguing;
use MusicBrainz\Value\Name;

/**
 * This relationship is used to link a catalogue work series to a person whose work it catalogues.
 *
 * @link https://musicbrainz.org/relationship/b792d0a6-a443-4e00-8882-c4f2bef56511
 */
class HasCatalogue extends WorkCataloguing
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('has catalogue');
    }
}
