<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\EventRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations an event stands in.
 */
trait EventRelationListTrait
{
    /**
     * A list of relations the event stands in.
     *
     * @var EventRelationList
     */
    private EventRelationList $relations;

    /**
     * Returns the list of relations the event stands in.
     *
     * @return EventRelationList
     */
    public function getRelations(): EventRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the event stands in by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setRelationsFromArray(array $input): void
    {
        if (!is_null(ArrayAccess::getArray($input, 'relation-list'))) {
            if (!is_null($array = ArrayAccess::getArray($input['relation-list'], 0))) {
                if (!is_null($array = ArrayAccess::getArray($array, 'relations'))) {
                    $this->relations = new EventRelationList($array);

                    return;
                }
            }

            $this->relations = new EventRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new EventRelationList($array)
            : new EventRelationList();
    }
}
