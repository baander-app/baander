<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\Instrument;
use MusicBrainz\Value\ValueList;

/**
 * A list of instrument relations
 */
class RelationToInstrumentList extends ValueList
{
    /**
     * Constructs a list of instrument relations.
     *
     * @param array $instrumentRelations A list of instrument relations
     */
    public function __construct(array $instrumentRelations = [])
    {
        parent::__construct($instrumentRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Instrument::class;
    }
}
