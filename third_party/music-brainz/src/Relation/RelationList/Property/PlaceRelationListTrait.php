<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\PlaceRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations an place stands in.
 */
trait PlaceRelationListTrait
{
    /**
     * A list of relations the place stands in.
     *
     * @var PlaceRelationList
     */
    private PlaceRelationList $relations;

    /**
     * Returns the list of relations the place stands in.
     *
     * @return PlaceRelationList
     */
    public function getRelations(): PlaceRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the place stands in by extracting it from a given input array.
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
                    $this->relations = new PlaceRelationList($array);

                    return;
                }
            }

            $this->relations = new PlaceRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new PlaceRelationList($array)
            : new PlaceRelationList();
    }
}
