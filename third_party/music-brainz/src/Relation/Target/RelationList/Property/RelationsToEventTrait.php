<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationToEvent;
use MusicBrainz\Value\EntityType;

use function is_null;

/**
 * Provides a getter for the list of relations to an event.
 */
trait RelationsToEventTrait
{
    /**
     * A list of relations to an event
     *
     * @var RelationToEvent[]|RelationToEventList
     */
    private RelationToEventList $eventRelations;

    /**
     * Returns a list of relations to an event.
     *
     * @return RelationToEvent[]|RelationToEventList
     */
    public function getEventRelations(): RelationToEventList
    {
        return $this->eventRelations;
    }

    /**
     * Sets a list of relations to an event by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setEventRelationsFromArray(array $input): void
    {
        $this->eventRelations = is_null($input = ArrayAccess::getArray($input, EntityType::EVENT))
            ? new RelationToEventList()
            : new RelationToEventList($input);
    }
}
