<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\InstrumentRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations an instrument stands in.
 */
trait InstrumentRelationListTrait
{
    /**
     * A list of relations the instrument stands in.
     *
     * @var InstrumentRelationList
     */
    private InstrumentRelationList $relations;

    /**
     * Returns the list of relations the instrument stands in.
     *
     * @return InstrumentRelationList
     */
    public function getRelations(): InstrumentRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the instrument stands in by extracting it from a given input array.
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
                    $this->relations = new InstrumentRelationList($array);

                    return;
                }
            }

            $this->relations = new InstrumentRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new InstrumentRelationList($array)
            : new InstrumentRelationList();
    }
}
