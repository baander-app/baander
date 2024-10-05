<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\Area;
use MusicBrainz\Value\ValueList;

/**
 * A list of area relations
 */
class RelationToAreaList extends ValueList
{
    /**
     * Constructs a list of area relations.
     *
     * @param array $areaRelations A list of area relations
     */
    public function __construct(array $areaRelations = [])
    {
        parent::__construct($areaRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Area::class;
    }
}
