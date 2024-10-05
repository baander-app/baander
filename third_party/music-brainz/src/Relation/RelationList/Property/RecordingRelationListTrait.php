<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\RelationList\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Relation\RelationList\RecordingRelationList;

use function is_null;

/**
 * Provides a getter for the list of relations a recording stands in.
 */
trait RecordingRelationListTrait
{
    /**
     * A list of relations the recording stands in.
     *
     * @var RecordingRelationList
     */
    private RecordingRelationList $relations;

    /**
     * Returns the list of relations the recording stands in.
     *
     * @return RecordingRelationList
     */
    public function getRelations(): RecordingRelationList
    {
        return $this->relations;
    }

    /**
     * Sets the list of relations the recording stands in by extracting it from a given input array.
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
                    $this->relations = new RecordingRelationList($array);

                    return;
                }
            }

            $this->relations = new RecordingRelationList();
        }

        $this->relations = ($array = ArrayAccess::getArray($input, 'relations'))
            ? new RecordingRelationList($array)
            : new RecordingRelationList();
    }
}
