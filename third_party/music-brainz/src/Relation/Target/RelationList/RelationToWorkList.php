<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\ValueList;
use MusicBrainz\Value\Work;

/**
 * A list of work relations
 */
class RelationToWorkList extends ValueList
{
    /**
     * Constructs a list of work relations.
     *
     * @param array $workRelations A list of work relations
     */
    public function __construct(array $workRelations = [])
    {
        parent::__construct($workRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Work::class;
    }
}
