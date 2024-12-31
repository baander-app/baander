<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\AreaRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations an area stands in.
 */
trait AreaRelationListTrait
{
    /**
     * A list of relations the area stands in.
     *
     * @var AreaRelationList
     */
    private AreaRelationList $relations;

    /**
     * Returns the list of relations the area stands in.
     *
     * @return AreaRelationList
     */
    public function getRelations(): AreaRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the area stands in by extracting it from a given input array.
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
                    $this->relations = new AreaRelationList($array);

                    return;
                }
            }

            $this->relations = new AreaRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new AreaRelationList($array)
            : new AreaRelationList();
    }
}
