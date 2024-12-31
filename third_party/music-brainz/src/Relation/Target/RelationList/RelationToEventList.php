<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList;

use MusicBrainz\Value\Event;
use MusicBrainz\Value\ValueList;

/**
 * A list of event relations
 */
class RelationToEventList extends ValueList
{
    /**
     * Constructs a list of event relations.
     *
     * @param array $eventRelations A list of event relations
     */
    public function __construct(array $eventRelations = [])
    {
        parent::__construct($eventRelations);
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Event::class;
    }
}
