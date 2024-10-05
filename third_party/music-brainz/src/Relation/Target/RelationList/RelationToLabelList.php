<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\Label;
use MusicBrainz\Value\ValueList;

/**
 * A list of label relations
 */
class RelationToLabelList extends ValueList
{
    /**
     * Constructs a list of label relations.
     *
     * @param array $labelRelations A list of label relations
     */
    public function __construct(array $labelRelations = [])
    {
        parent::__construct($labelRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Label::class;
    }
}
