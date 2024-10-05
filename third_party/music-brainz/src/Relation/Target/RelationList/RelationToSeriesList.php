<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\Series;
use MusicBrainz\Value\ValueList;

/**
 * A list of series relations
 */
class RelationToSeriesList extends ValueList
{
    /**
     * Constructs a list of series relations.
     *
     * @param array $seriesRelations A list of series relations
     */
    public function __construct(array $seriesRelations = [])
    {
        parent::__construct($seriesRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Series::class;
    }
}
